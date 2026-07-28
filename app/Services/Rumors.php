<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\EvolutionRun;
use App\Models\Rumor;
use App\Models\Scene;
use App\Models\Turn;
use App\Models\Zone;

/**
 * The world's news, reaching the character at last.
 *
 * The living world is this game's whole premise and it used to happen entirely
 * off-stage. `WorldEvolver` tends each active tale's world overnight, logs its
 * clamped changes, and publishes a Chronicle chapter with a push — the READER
 * is told. The character never heard a word of it: no scene, no conversation,
 * no crossing ever referenced what had changed. The best feature in the game
 * was invisible from inside it.
 *
 * Rumors are an extraction and a delivery schedule, not a new content
 * generator. Four rules hold the whole thing up.
 *
 * 1. TRUE, and engine-templated. Every row derives from a fact that was really
 *    logged — a clamped evolution change, a frontier zone that was really
 *    forged. The line is written the instant the source fact exists; Claude is
 *    never asked to make up news, and an empty queue yields silence.
 * 2. COLOUR, and nothing else. A rumor is never a card, an odds part, a board
 *    group, or a resolver path. It reveals nothing in the CURRENT scene —
 *    rumors are news about ELSEWHERE — and hearing that an old score is
 *    simmering never touches the return roll.
 * 3. It rides EXISTING channels. No new card, no new stance, no push: the
 *    resolver detects one of three moments it already produced and hands the
 *    pick here, the way it hands the shelf its moments.
 * 4. It is NOT the Chronicle. The Chronicle stays the reader's omniscient
 *    digest and keeps its push. This is diegetic — what the character
 *    plausibly heard, days late and one at a time. Hearing an echo in play of
 *    something the reader already knows is the point.
 */
class Rumors
{
    /** Met on the road: a scene transition, or a crossing into new country. */
    public const CROSSING = 'crossing';

    /** They pass on what they heard: a social beat that landed on somebody willing. */
    public const TALK = 'talk';

    /** Overheard, found posted, read in the ashes: a wait spent walking or watching. */
    public const FIRESIDE = 'fireside';

    /** @var list<string> Checked in this order; the first that qualifies delivers. */
    public const CHANNELS = [self::CROSSING, self::TALK, self::FIRESIDE];

    /** The clamp on anything Claude proposes back — the mementos rule, verbatim. */
    public const MAX_LINE_WORDS = 20;

    /** Words too ordinary to prove a rewording is still about the same subject. */
    private const HOLLOW_WORDS = ['the', 'a', 'an', 'of', 'and', 'that', 'this', 'their', 'them', 'with', 'from', 'some'];

    /** No mechanics language reaches a chapter, from any author. */
    private const MECHANICS_PATTERN = '/\b(dice|die|dc|rolls?|cards?|meters?|difficulty|modifiers?|hit points|health)\b/iu';

    /**
     * Write one candidate onto the queue.
     *
     * Producer-side and engine-worded: whoever logged the fact writes the
     * sentence, right there, while the fact is still in hand. The queue is
     * trimmed from the FRONT afterwards — news nobody ever got round to
     * hearing goes stale on its own, and a queue that grew without bound would
     * have the character hearing about last month all year.
     */
    public static function offer(
        Campaign $campaign,
        string $source,
        string $subject,
        string $line,
        ?Zone $zone = null,
        ?EvolutionRun $run = null,
    ): ?Rumor {
        $line = trim($line);

        if ($line === '' || trim($subject) === '') {
            return null;
        }

        $rumor = Rumor::create([
            'campaign_id' => $campaign->id,
            'source' => $source,
            'evolution_run_id' => $run?->id,
            'subject' => trim($subject),
            'subject_zone_id' => $zone?->id,
            'line' => $line,
        ]);

        self::trim($campaign);

        return $rumor->exists ? $rumor->fresh() : null;
    }

    /**
     * The evolver's side of it: one candidate per rumor-worthy change, drawn
     * from the CLAMPED change set rather than from the plan, so nothing the
     * engine refused can be gossiped about.
     *
     * Ordered most personal first — somebody asking after you by name outranks
     * a new rock formation — and capped, because an evolution run is a night's
     * gossip and not a gazette.
     *
     * @param  array<string,mixed>  $applied  The run's own logged change set.
     * @return list<Rumor>
     */
    public static function fromEvolution(Campaign $campaign, EvolutionRun $run, array $applied): array
    {
        $zones = Zone::where('campaign_id', $campaign->id)->get()->keyBy('slug');
        $candidates = [];

        foreach ($applied['grudges'] ?? [] as $grudge) {
            $name = trim((string) ($grudge['name'] ?? ''));
            if ($name !== '') {
                $candidates[] = [
                    'source' => Rumor::GRUDGE,
                    'subject' => $name,
                    'zone' => null,
                    'line' => "Somebody has been asking after them by name, and the name they gave was {$name}.",
                ];
            }
        }

        foreach ($applied['actors'] ?? [] as $actor) {
            $name = trim((string) ($actor['name'] ?? ''));
            $zone = $zones[$actor['zone_slug'] ?? ''] ?? null;
            if ($name !== '' && $zone !== null) {
                $candidates[] = [
                    'source' => Rumor::EVOLUTION,
                    'subject' => $name,
                    'zone' => $zone,
                    'line' => "Word going round is that {$name} has been seen about {$zone->name} lately.",
                ];
            }
        }

        foreach ($applied['features'] ?? [] as $feature) {
            $name = trim((string) ($feature['name'] ?? ''));
            $zone = $zones[$feature['zone_slug'] ?? ''] ?? null;
            if ($name !== '' && $zone !== null) {
                $candidates[] = [
                    'source' => Rumor::EVOLUTION,
                    'subject' => $name,
                    'zone' => $zone,
                    'line' => "People coming out of {$zone->name} say {$name} is there now, and was not before.",
                ];
            }
        }

        foreach ($applied['items'] ?? [] as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $candidates[] = [
                    'source' => Rumor::EVOLUTION,
                    'subject' => $name,
                    'zone' => null,
                    'line' => "There is talk of {$name} having surfaced somewhere out in the world.",
                ];
            }
        }

        $written = [];

        foreach (array_slice($candidates, 0, max(0, (int) config('game.rumors.per_run', 3))) as $candidate) {
            $rumor = self::offer(
                $campaign, $candidate['source'], $candidate['subject'],
                $candidate['line'], $candidate['zone'], $run,
            );
            if ($rumor !== null) {
                $written[] = $rumor;
            }
        }

        return $written;
    }

    /**
     * The frontier's side of it: the road ahead gets a voice before anybody
     * walks it, so the venture card's destination is a place the character has
     * heard of rather than a name that appears from nowhere.
     */
    public static function fromForge(Campaign $campaign, Zone $zone): ?Rumor
    {
        return self::offer(
            $campaign, Rumor::FORGE, $zone->name,
            "Travellers speak of {$zone->name}, out past the edge of this ground.",
            $zone,
        );
    }

    /**
     * A side thread's side of it: somebody the player saw through their own
     * small story, and the story that got about afterwards.
     *
     * The only source play itself writes, and it obeys every rule the others
     * do — engine-templated from a fact already fixed (a `threads` row that
     * really filled), inert in every direction, and subject to the same queue
     * cap. The engine hands the line outward from here rather than naming the
     * model, exactly as the resolver does with the delivery moment.
     */
    public static function fromThread(Campaign $campaign, string $subject, string $line): ?Rumor
    {
        return self::offer($campaign, Rumor::THREAD, $subject, $line);
    }

    /**
     * Deliver at most one, if this turn produced a moment for it.
     *
     * The resolver decides the CHANNEL (it is the only thing that knows what
     * the turn did); this decides WHICH, and stamps it. Oldest first, so news
     * arrives in the order the world made it — with one exception: on a
     * crossing, word about where the road leads outranks everything, because
     * that is the one moment it is worth more than anything else in the queue.
     *
     * A rumor about ground the tale has already walked is stale. It is skipped
     * AND stamped: the character can see that place with their own eyes now,
     * and leaving it in the queue would mean hearing about it forever.
     *
     * @param  string|null  $channel  One of CHANNELS, or null for a turn that
     *                                produced no moment (and for any turn with a
     *                                fight still standing — nobody trades news
     *                                mid-fight, and that is the resolver's call).
     * @param  int|null  $preferZoneId  The un-walked country the road leads to.
     * @return array{line:string,channel:string,subject:string}|null
     */
    public static function deliver(Turn $turn, ?string $channel, ?int $preferZoneId = null): ?array
    {
        if ($channel === null || ! in_array($channel, self::CHANNELS, true) || $turn->campaign_id === null) {
            return null;
        }

        // One per chapter, and a chapter is one turn's telling.
        $cap = max(0, (int) config('game.rumors.per_chapter', 1));
        if ($cap === 0 || Rumor::where('heard_turn_id', $turn->id)->count() >= $cap) {
            return null;
        }

        $walked = Scene::where('campaign_id', $turn->campaign_id)
            ->whereNotNull('zone_id')->distinct()->pluck('zone_id')->all();

        $queue = Rumor::where('campaign_id', $turn->campaign_id)
            ->whereNull('heard_turn_id')->orderBy('id')->get();

        // The road's own news first, then everything else in the order the
        // world made it. A stable partition, so oldest-first still holds
        // inside each half.
        if ($channel === self::CROSSING && $preferZoneId !== null) {
            $queue = $queue->sortBy(
                fn (Rumor $r) => $r->subject_zone_id === $preferZoneId ? 0 : 1,
            )->values();
        }

        foreach ($queue as $rumor) {
            if ($rumor->subject_zone_id !== null && in_array($rumor->subject_zone_id, $walked, true)) {
                // They are standing in it. Mark it heard and move on: silence
                // about a place you can see is not a gap, it is good manners.
                $rumor->update(['heard_turn_id' => $turn->id]);

                continue;
            }

            $rumor->update(['heard_turn_id' => $turn->id]);

            return ['line' => $rumor->line, 'channel' => $channel, 'subject' => $rumor->subject];
        }

        return null;
    }

    /**
     * The narrator's block. One line, and only when this chapter actually
     * heard something — an ordinary chapter carries no instructions about
     * news. Returns '' otherwise.
     */
    public static function narratorBlock(Turn $turn): string
    {
        $heard = $turn->resolution['rumor'] ?? null;

        if (! is_array($heard) || ($heard['line'] ?? '') === '') {
            return '';
        }

        $how = match ($heard['channel'] ?? null) {
            self::CROSSING => 'They had it on the road — from somebody going the other way, or off something left where travellers pass.',
            self::TALK => 'They had it from the person they were talking to, offered up in the middle of it.',
            default => 'They came by it during the wait — overheard, or found posted, or read in the leavings of somebody else\'s camp.',
        };

        $words = self::MAX_LINE_WORDS;
        $line = $heard['line'];

        return <<<NEWS

        ## Something they heard about elsewhere (fixed fact)
        {$line}
        {$how}
        This is news from somewhere the chapter is NOT: give it one small moment inside the action and let it pass. It changes nothing here, nobody acts on it this chapter, and it must never touch what is standing in this scene.
        If it lands better in this land's own words, reword it in one plain sentence of at most {$words} — still about the same thing. Otherwise repeat it as given.

        NEWS;
    }

    /**
     * Claude's proposal, clamped — and the chapter stamp that completes the
     * rumor's provenance.
     *
     * The row already exists and already reads honestly; this can only replace
     * its line, and only when the proposal is short enough, still about the
     * same subject, and free of mechanics language. Any violation, or no
     * proposal at all, and the engine's words stand.
     */
    public static function reword(Turn $turn, ?Chapter $chapter, mixed $proposed): void
    {
        $rumor = self::forTurn($turn);

        if ($rumor === null) {
            return;
        }

        $update = [];

        if ($chapter !== null && $rumor->heard_chapter_id === null) {
            $update['heard_chapter_id'] = $chapter->id;
        }

        $line = is_string($proposed) ? trim($proposed) : '';

        if (self::withinWords($line, self::MAX_LINE_WORDS)
            && self::mentionsSubject($line, $rumor->subject)
            && preg_match(self::MECHANICS_PATTERN, $line) !== 1) {
            $update['line'] = $line;
        }

        if ($update !== []) {
            $rumor->update($update);
        }
    }

    /** What this turn heard, if it heard anything. */
    public static function forTurn(Turn $turn): ?Rumor
    {
        return Rumor::where('heard_turn_id', $turn->id)->orderBy('id')->first();
    }

    /** How many pieces of news are still waiting on a moment. */
    public static function pending(Campaign $campaign): int
    {
        return Rumor::where('campaign_id', $campaign->id)->whereNull('heard_turn_id')->count();
    }

    /** The queue cap, enforced from the front: the oldest unheard news goes first. */
    private static function trim(Campaign $campaign): void
    {
        $cap = max(1, (int) config('game.rumors.queue', 12));

        $waiting = Rumor::where('campaign_id', $campaign->id)
            ->whereNull('heard_turn_id')->orderBy('id')->pluck('id');

        if ($waiting->count() <= $cap) {
            return;
        }

        Rumor::whereIn('id', $waiting->take($waiting->count() - $cap))->delete();
    }

    private static function withinWords(string $text, int $max): bool
    {
        return $text !== '' && count(preg_split('/\s+/', $text)) <= $max;
    }

    /**
     * Still about the same thing. A rewording that quietly swaps the subject is
     * a different piece of news wearing this one's row, so it is refused.
     */
    private static function mentionsSubject(string $text, string $subject): bool
    {
        $text = mb_strtolower($text);

        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($subject)))
            ->filter(fn (string $w) => mb_strlen($w) >= 4 && ! in_array($w, self::HOLLOW_WORDS, true))
            ->values();

        if ($words->isEmpty()) {
            return str_contains($text, mb_strtolower(trim($subject)));
        }

        return $words->contains(fn (string $word) => str_contains($text, $word));
    }
}
