<?php

namespace App\Game\Engine;

use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Grudge;
use App\Models\Scene;
use App\Models\Turn;
use Illuminate\Support\Collection;

/**
 * The tale's enemies remember. An enemy who flees a resolved turn becomes a
 * campaign-scoped grudge the evolver tends and the engine can bring back —
 * changed, and carrying history — instead of despawning into nothing.
 *
 * The engine decides WHETHER and WHEN a grudge returns; Claude only ever
 * narrates it and (via the evolver, within clamps) proposes how they changed
 * off-screen. A returned grudge is a normal actor to every downstream system
 * — cards, intents, lurking, the board — with no special-case combat logic.
 */
class Grudges
{
    /** Return chance per point of heat, rolled at each scene transition. */
    public const RETURN_CHANCE_PER_HEAT = 0.15;

    /** Chapters that must pass since last seen — a return must never feel instant. */
    public const RETURN_CHAPTER_FLOOR = 2;

    public const MAX_HEAT = 3;

    /**
     * The closed list of what a scheming grudge may put on the table. The
     * deal's mechanical content is engine-picked and engine-applied; Claude
     * never invents an outcome.
     */
    public const DEALS = ['call_off', 'reveal_hidden', 'depart'];

    /**
     * Scene-transient tags that must never travel through a grudge's
     * snapshot into its return: combat state, hiding state, and the
     * grudge machinery's own markers.
     */
    private const TRANSIENT_TAGS = [
        'intent', 'angle', 'ambush', 'shaken', 'cornered', 'pinned',
        'lurking', 'lurking_since', 'fled_how', 'called_off',
        'grudge_id', 'truce', 'deal', 'truce_health',
    ];

    /**
     * The sweep behind the flee writes: every enemy who newly broke and ran
     * this turn becomes (or re-heats) a grudge. One sweep instead of a hook
     * on each write site, so a future way to flee is remembered for free.
     *
     * @param  list<int>  $fledBefore  Actor ids already fled when the turn began.
     */
    public static function recordFlights(Scene $scene, Turn $turn, array $fledBefore): void
    {
        if ($scene->campaign_id === null) {
            return;
        }

        $fled = $scene->actors()->where('status', 'fled')->where('kind', 'enemy')->get()
            ->reject(fn (Actor $a) => in_array($a->id, $fledBefore, true))
            // A bargain concluded or an ambush called off is a departure by
            // agreement, not a flight the world holds against anyone.
            ->reject(fn (Actor $a) => $a->tags['called_off'] ?? false);

        foreach ($fled as $actor) {
            self::recordFlight($actor, $scene, $turn);
        }
    }

    private static function recordFlight(Actor $actor, Scene $scene, Turn $turn): void
    {
        // The name is the identity. Two actors sharing it in one scene means
        // this one is a faceless template dupe — no identity, no grudge.
        if ($scene->actors()->where('name', $actor->name)->where('id', '!=', $actor->id)->exists()) {
            return;
        }

        $grudge = Grudge::where('campaign_id', $scene->campaign_id)
            ->where('actor_name', $actor->name)->first();

        // Resolved is terminal: a settled score never reopens.
        if ($grudge?->status === 'resolved') {
            return;
        }

        $place = $scene->title;
        $lastChapterId = Chapter::where('campaign_id', $scene->campaign_id)->max('id');

        if ($grudge !== null) {
            // A re-flee heats the grudge instead of duplicating it, and the
            // snapshot refreshes — they leave as whoever they are now.
            $grudge->update([
                'heat' => min(self::MAX_HEAT, $grudge->heat + 1),
                'stats' => $actor->stats,
                'tags' => self::snapshotTags($actor),
                'status' => 'simmering',
                'history' => [...$grudge->history, self::entry(
                    $grudge->status === 'returning' ? 'escaped_again' : 'fled',
                    "Slipped away again at {$place}.",
                    $turn, $lastChapterId, $place,
                )],
                'last_seen_chapter_id' => $lastChapterId,
            ]);

            return;
        }

        // Disposition comes from the flee circumstances, engine-rolled and
        // never read by difficulty: it only colors how they come back.
        $health = $actor->stats['health'] ?? null;
        $wounded = $health !== null && ($health['current'] ?? 0) < ($health['max'] ?? 0);
        $how = $actor->tags['fled_how'] ?? null;

        [$disposition, $detail] = match (true) {
            $wounded => ['vengeful', "Fled wounded at {$place}, and carried the wound away with them."],
            $how === 'intimidated' => ['wary', "Broke and ran at {$place}, cowed but unhurt."],
            default => ['scheming', "Walked away from {$place} on their own terms, untouched."],
        };

        Grudge::create([
            'campaign_id' => $scene->campaign_id,
            'actor_name' => $actor->name,
            'stats' => $actor->stats,
            'tags' => self::snapshotTags($actor),
            'tier' => $actor->tier,
            'history' => [self::entry('fled', $detail, $turn, $lastChapterId, $place)],
            'heat' => 1,
            'disposition' => $disposition,
            'status' => 'simmering',
            'last_seen_chapter_id' => $lastChapterId,
        ]);
    }

    /**
     * A tracked quarry the transition just delivered, cornered, into the new
     * scene: that IS the grudge returning — by the player's hand instead of
     * the dice's.
     */
    public static function recordCornered(Actor $quarry, Scene $next, Turn $turn): void
    {
        $grudge = Grudge::where('campaign_id', $next->campaign_id)
            ->where('actor_name', $quarry->name)
            ->where('status', '!=', 'resolved')->first();

        if ($grudge === null) {
            return;
        }

        $quarry->update(['tags' => array_merge($quarry->tags ?? [], ['grudge_id' => $grudge->id])]);

        $grudge->update([
            'status' => 'returning',
            'history' => [...$grudge->history, self::entry(
                'returned', "Run to ground at {$next->title} — cornered, with nowhere left to run.",
                $turn, null, $next->title,
            )],
        ]);
    }

    /**
     * The day they put you on the floor.
     *
     * A fall is the single most memorable thing that can pass between the
     * player and an enemy, and until now the tale's memory did not record it —
     * an old score could come back for a third time still describing itself as
     * having merely fled. Every grudged figure standing in the scene when the
     * character went down gets the line; the narrator quotes it at the reunion,
     * which is where the whole payoff of this lives.
     *
     * The score is not settled by it: heat, disposition, and status are the
     * return machinery's business and are left exactly as they were.
     */
    public static function recordDowning(Scene $scene, Turn $turn): void
    {
        $present = $scene->actors()->where('status', 'active')->where('kind', 'enemy')->get()
            ->filter(fn (Actor $a) => isset($a->tags['grudge_id']));

        foreach ($present as $actor) {
            $grudge = Grudge::find($actor->tags['grudge_id']);
            if ($grudge === null || $grudge->status === 'resolved') {
                continue;
            }

            $grudge->update(['history' => [...$grudge->history, self::entry(
                'downed_you', "Put them on the floor at {$scene->title}, and walked away from it.",
                $turn, null, $scene->title,
            )]]);
        }
    }

    /**
     * The return decision, rolled when new ground is dressed. Engine-side
     * and seeded: heat sets the chance, the chapter floor keeps a return
     * from ever feeling instant, and at most ONE grudge enters per scene.
     */
    public static function maybeReturn(Scene $next, Campaign $campaign, Dice $dice, Turn $turn): ?Actor
    {
        $chapterNow = (int) $campaign->chapters()->max('number');

        $candidates = Grudge::where('campaign_id', $campaign->id)
            ->where('status', 'simmering')->orderBy('id')->get()
            ->filter(function (Grudge $grudge) use ($chapterNow) {
                $lastSeen = (int) ($grudge->lastSeenChapter?->number ?? 0);

                return $chapterNow - $lastSeen >= self::RETURN_CHAPTER_FLOOR;
            });

        foreach ($candidates as $grudge) {
            if ($dice->chance(min(self::MAX_HEAT, $grudge->heat) * self::RETURN_CHANCE_PER_HEAT)) {
                return self::spawnReturn($grudge, $next, $dice, $turn);
            }
        }

        return null;
    }

    /**
     * Spawn the grudge into the scene from its stored snapshot. Disposition
     * picks the entry mode — never the odds: vengeful arrives in the open
     * with an aggressive intent telegraphed, wary slips in lurking (the
     * existing ambush machinery), scheming approaches under truce carrying
     * an engine-picked deal.
     */
    private static function spawnReturn(Grudge $grudge, Scene $next, Dice $dice, Turn $turn): Actor
    {
        // The chapters between flee and return healed them; how they CHANGED
        // is the evolver's business, applied to the snapshot before now.
        $stats = $grudge->stats;
        if (isset($stats['health']['max'])) {
            $stats['health']['current'] = $stats['health']['max'];
        }

        $tags = $grudge->tags ?? [];
        $tags['grudge_id'] = $grudge->id;

        $history = [self::entry('returned', match ($grudge->disposition) {
            'vengeful' => "Came back for them at {$next->title}, making no secret of it.",
            'wary' => "Came back at {$next->title} — carefully, keeping to the edges.",
            default => "Came back at {$next->title} carrying an offer instead of a blade.",
        }, $turn, null, $next->title)];

        switch ($grudge->disposition) {
            case 'vengeful':
                $tags['intent'] = 'press';
                break;
            case 'wary':
                // Hidden is hidden: invisible to cards, situation, and
                // narration until detect exposes them or the ambush springs.
                $tags['lurking'] = true;
                $tags['lurking_since'] = $turn->number;
                break;
            default:
                $tags['truce'] = true;
                $tags['deal'] = self::pickDeal($next, $dice);
                $tags['truce_health'] = $stats['health']['current'] ?? 1;
                $history[] = self::entry('deal_offered', self::dealDetail($tags['deal']), $turn, null, $next->title);
        }

        $actor = Actor::create([
            'scene_id' => $next->id,
            'zone_id' => $next->zone_id,
            'name' => $grudge->actor_name,
            'kind' => 'enemy',
            'tier' => $grudge->tier,
            'stats' => $stats,
            'tags' => $tags,
            'status' => 'active',
            'source' => 'grudge',
        ]);

        $grudge->update([
            'status' => 'returning',
            'history' => [...$grudge->history, ...$history],
        ]);

        return $actor;
    }

    /**
     * Settle every returning grudge against what the turn actually did to
     * its actor: killed or kept resolves the score for good; a player who
     * walks away leaves it simmering for another day. (Fleeing again is the
     * flights sweep's business and has already re-simmered the grudge.)
     */
    public static function settle(Scene $before, Scene $after, bool $moved, Turn $turn): void
    {
        $returning = Grudge::where('campaign_id', $before->campaign_id)
            ->where('status', 'returning')->get();

        if ($returning->isEmpty()) {
            return;
        }

        $actors = $before->actors()->get()
            ->when($moved, fn ($all) => $all->concat($after->actors()->get()))
            ->filter(fn (Actor $a) => isset($a->tags['grudge_id']))
            ->keyBy(fn (Actor $a) => (int) $a->tags['grudge_id']);

        foreach ($returning as $grudge) {
            $actor = $actors->get($grudge->id);
            if ($actor === null) {
                continue;
            }

            // A truce holds only while it is honored: blood drawn under it
            // ends the talking, and next turn they fight like anyone else.
            if ($actor->status === 'active' && ($actor->tags['truce'] ?? false)
                && ($actor->stats['health']['current'] ?? 0) < ($actor->tags['truce_health'] ?? 0)) {
                $tags = $actor->tags;
                unset($tags['truce'], $tags['deal'], $tags['truce_health']);
                $tags['intent'] = 'press';
                $actor->update(['tags' => $tags]);
            }

            if (in_array($actor->status, ['defeated', 'dead'], true)) {
                self::resolve($grudge, "The score ended at {$before->title}: they went down and did not rise.", $turn);
            } elseif ($actor->status === 'restrained' && $moved) {
                self::resolve($grudge, 'Taken and kept: bound, and carried out of the tale still bound.', $turn);
            } elseif ($moved && $actor->scene_id === $before->id) {
                // The player moved on with the score still open. Back to
                // simmering — the world will pick its own moment to try again.
                $grudge->update([
                    'status' => 'simmering',
                    'history' => [...$grudge->history, self::entry(
                        'escaped_again', "The encounter at {$before->title} broke off with nothing settled.",
                        $turn, null, $before->title,
                    )],
                    'last_seen_chapter_id' => Chapter::where('campaign_id', $before->campaign_id)->max('id'),
                ]);
            }
        }
    }

    /**
     * The bargain beat: accepting a scheming grudge's terms. Roll-free —
     * the deal was theirs to offer — and the whole mechanical content comes
     * from the closed deal list picked at return time. However it lands,
     * the score is settled and they walk; a settled score never reopens.
     *
     * @return list<string>
     */
    public static function strikeBargain(array $card, Scene $scene, Turn $turn): array
    {
        $actor = Actor::find($card['target']['id'] ?? 0);
        if ($actor === null || $actor->status !== 'active' || ! ($actor->tags['truce'] ?? false)) {
            return ['The moment for terms had already passed.'];
        }

        $deal = $actor->tags['deal'] ?? 'depart';
        $facts = ["They heard {$actor->name} out, and took the terms."];

        if ($deal === 'call_off') {
            // The trouble headed this way turns around: the alarm dies, and
            // anything lurking slips out the way it slipped in — silently,
            // still unknown to the player and the narrator both.
            $scene->update(['state' => array_merge($scene->state ?? [], ['alarm' => 0])]);
            foreach ($scene->actors()->where('status', 'active')->get() as $lurker) {
                if (($lurker->tags['lurking'] ?? false) && $lurker->id !== $actor->id) {
                    $lurker->update(['status' => 'fled', 'tags' => $lurker->tags + ['called_off' => true]]);
                }
            }
            $facts[] = "{$actor->name} kept their side of it: whatever trouble was coming for this place was turned around.";
        } elseif ($deal === 'reveal_hidden') {
            $hidden = $scene->features()->get()->first(
                fn ($f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
            );
            if ($hidden !== null) {
                $hidden->update(['state' => array_merge($hidden->state ?? [], ['hidden' => false])]);
                $facts[] = "{$actor->name} gave up what this place hides: {$hidden->name}.";
            } else {
                $facts[] = "{$actor->name} had nothing left to show that had not already been found — but the offer was honest when it was made.";
            }
        } else {
            $facts[] = "{$actor->name} turned and walked, and will not cross this tale again.";
        }

        $tags = $actor->tags;
        unset($tags['truce'], $tags['deal'], $tags['truce_health']);
        $tags['called_off'] = true;
        $actor->update(['status' => 'fled', 'tags' => $tags]);

        $grudge = Grudge::find($actor->tags['grudge_id'] ?? 0);
        $grudge?->update([
            'status' => 'resolved',
            'history' => [...$grudge->history, self::entry(
                'resolved', 'The score was settled with words: '.lcfirst(self::dealDetail($deal)),
                $turn, null, $scene->title,
            )],
        ]);

        return $facts;
    }

    /**
     * Every score this turn closed for good, by name.
     *
     * Read off the append-only history rather than a flag, so it answers for
     * all three ways a grudge ends — killed, kept, or bargained out — without
     * any of them having to remember to announce it. Nothing in the engine
     * consumes this; it exists so the turn's own facts can say a rival was
     * settled, which is one of the moments the tale keeps a keepsake from.
     *
     * @return list<string>
     */
    public static function settledNames(Turn $turn): array
    {
        return Grudge::where('campaign_id', $turn->campaign_id)
            ->where('status', 'resolved')->orderBy('id')->get()
            ->filter(fn (Grudge $grudge) => collect($grudge->history)->contains(
                fn (array $entry) => ($entry['event'] ?? null) === 'resolved'
                    && ($entry['turn_id'] ?? null) === $turn->id,
            ))
            ->pluck('actor_name')->values()->all();
    }

    /**
     * The board's line for a returned grudge the player can see: the fact
     * that they have met, and where the last parting happened.
     *
     * @param  Collection<int, Actor>  $actors
     * @return list<string>
     */
    public static function boardLines($actors): array
    {
        $lines = [];

        foreach ($actors as $actor) {
            $grudge = Grudge::find($actor->tags['grudge_id'] ?? 0);
            if ($grudge === null) {
                continue;
            }

            $place = collect($grudge->history)
                ->filter(fn (array $e) => in_array($e['event'], ['fled', 'escaped_again'], true) && ($e['place'] ?? null) !== null)
                ->last()['place'] ?? null;

            $lines[] = $actor->name.' — you have met before'
                .($place === null ? '' : "; they fled you at {$place}").'.';
        }

        return $lines;
    }

    /**
     * The narrator's block for every returned grudge standing visible in the
     * turn's scene: name, disposition color, and the history — already prose
     * facts, no mechanics language. Empty string when no old score is here,
     * so an ordinary chapter carries no reunion instructions. Hidden stays
     * hidden: a lurking return never reaches this.
     */
    public static function returningFigures(Turn $turn): string
    {
        $scene = $turn->scene()->first() ?? $turn->campaign->activeScene;
        if ($scene === null) {
            return '';
        }

        $blocks = [];

        foreach ($scene->visibleActors() as $actor) {
            $grudge = Grudge::find($actor->tags['grudge_id'] ?? 0);
            if ($grudge === null) {
                continue;
            }

            $manner = match ($grudge->disposition) {
                'vengeful' => 'carrying the grudge openly',
                'wary' => 'warier than they were',
                default => 'with an offer, not a blade',
            };

            $history = collect($grudge->history)->map(fn (array $e) => '- '.$e['detail'])->join("\n");

            $blocks[] = "{$actor->name} has crossed this tale's path before, and now returns {$manner}. What has passed between them:\n{$history}";
        }

        if ($blocks === []) {
            return '';
        }

        return "\n## Returning figure (the reader has met them before — write a reunion, never an introduction)\n"
            .implode("\n\n", $blocks)."\n";
    }

    private static function pickDeal(Scene $scene, Dice $dice): string
    {
        $deals = ['call_off', 'depart'];

        $hidden = $scene->features()->get()->contains(
            fn ($f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
        );
        if ($hidden) {
            $deals[] = 'reveal_hidden';
        }

        return $deals[$dice->between(0, count($deals) - 1)];
    }

    /** The deal's terms in plain words — the card quotes these, and so does the history. */
    public static function dealDetail(string $deal): string
    {
        return match ($deal) {
            'call_off' => 'They offered to turn the coming trouble around and let the score die.',
            'reveal_hidden' => 'They offered to show what this place hides, and let the score die.',
            default => 'They offered to walk away for good, and let the score die.',
        };
    }

    private static function resolve(Grudge $grudge, string $detail, Turn $turn): void
    {
        $grudge->update([
            'status' => 'resolved',
            'history' => [...$grudge->history, self::entry('resolved', $detail, $turn, null, null)],
        ]);
    }

    /** @return array{turn_id:?int,chapter_id:?int,event:string,detail:string,place:?string} */
    private static function entry(string $event, string $detail, ?Turn $turn, ?int $chapterId, ?string $place): array
    {
        return [
            'turn_id' => $turn?->id,
            'chapter_id' => $chapterId,
            'event' => $event,
            'detail' => $detail,
            'place' => $place,
        ];
    }

    /** The actor's tags as a durable snapshot: nature travels, combat state does not. */
    private static function snapshotTags(Actor $actor): array
    {
        return array_diff_key($actor->tags ?? [], array_flip(self::TRANSIENT_TAGS));
    }
}
