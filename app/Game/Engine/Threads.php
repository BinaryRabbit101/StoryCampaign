<?php

namespace App\Game\Engine;

use App\Game\Verb;
use App\Game\VerbFamily;
use App\Models\Actor;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Thread;
use App\Models\Turn;
use App\Services\Rumors;

/**
 * Side threads: someone else's small story.
 *
 * Everything the world wanted, up to now, it wanted AT the player. Enemies had
 * intents, grudges had heat, the alarm had a count — and every bystander in the
 * game was furniture that spoke, with no continuity past the scene they stood
 * in. One person visibly wanting something of their own, and visibly better or
 * worse off for how the player answered, buys more world than a dozen more
 * dressed features.
 *
 * Structurally a thread is a clock owned by an NPC, and it is deliberately
 * built out of parts that already existed. Five rules hold it in shape:
 *
 *  - ENGINE-AUTHORED, from the closed kind table below. Claude narrates a want
 *    the engine chose and templated. The person's own words about it are
 *    narration; the want's facts are engine rows.
 *  - DORMANT UNTIL DISCOVERED. Until a social or inspect beat lands on the
 *    person, the row reaches no card, no board group and no prompt. That is the
 *    hidden-is-hidden rule applied to story instead of to ground, and it is why
 *    ticks do not accrue before the reveal either: a payoff that fired for a
 *    player who never met the person would be a revelation out of nowhere.
 *  - HELPED THROUGH ORDINARY CARDS. No new verb, no new slot, no payload
 *    change. Qualifying beats carry a forecast line the way an endeavor's do,
 *    read off this row so the promise and the tick cannot drift.
 *  - PAYOFFS COME FROM EXISTING MACHINERY. A sanctioned reveal (or the scouted
 *    exit the composer already honors), one rumors row, and the consensual
 *    welcome/decline pair Companions already owns — this only decides WHEN.
 *  - NEGLECT IS REAL AND ONLY FICTIONAL. Walking past costs the PERSON: their
 *    want resolves badly and one plain fact says so. It costs the player no
 *    number anywhere — no odds part, no scar, nothing on the sheet. The tale is
 *    theirs, and a side thread is a window, not a hook with a barb.
 */
class Threads
{
    /** They are looking for something this place is keeping back. */
    public const SEEKING = 'seeking';

    /** Something here is broken, and they mean to put it right. */
    public const MENDING = 'mending';

    /** They are trying to get out of this country, and would rather not go alone. */
    public const ROAD = 'road';

    /** The closed payoff list. Each routes through machinery that existed. */
    public const REVELATION = 'revelation';

    public const TOLD_TALE = 'told_tale';

    public const COMPANIONSHIP = 'companionship';

    /** Which payoff each want ends in. Derived, never stored: one table, one truth. */
    private const PAYOFFS = [
        self::SEEKING => self::REVELATION,
        self::MENDING => self::TOLD_TALE,
        self::ROAD => self::COMPANIONSHIP,
    ];

    /**
     * Beats that turn something up. The gate is not the die (`examine` and
     * `inspect` cast none) but the EXPOSURE: a search only moves on a beat that
     * actually dragged something into the light, and every scene has a finite
     * amount it is holding back. That is a harder limit than a roll would be.
     */
    private const REVEAL_VERBS = ['examine', 'inspect', 'scout', 'detect'];

    /** Beats that count as work done on the named thing, and only on it. */
    private const MEND_VERBS = ['break', 'lift', 'haul', 'recover', 'improvise'];

    /** Statuses that mean the person is not there to want anything any more. */
    private const GONE = ['dead', 'departed'];

    // ---------------------------------------------------------------- reading

    /** The one want this tale may carry at a time, wherever its owner stands. */
    public static function openFor(?int $campaignId): ?Thread
    {
        if ($campaignId === null) {
            return null;
        }

        return Thread::where('campaign_id', $campaignId)
            ->where('status', 'open')->orderBy('id')->first();
    }

    /**
     * The open want standing on this ground and already discovered — the only
     * form of it anything player-facing is allowed to see.
     */
    public static function shownOn(?Scene $scene): ?Thread
    {
        if ($scene === null) {
            return null;
        }

        $thread = self::openFor($scene->campaign_id);

        if ($thread === null || ! $thread->revealed) {
            return null;
        }

        $actor = Actor::find($thread->actor_id);

        return ($actor !== null && $actor->scene_id === $scene->id) ? $thread : null;
    }

    /** What this want is called, in plain words, off the row and nothing else. */
    public static function title(Thread $thread): string
    {
        return $thread->actor_name.match ($thread->kind) {
            self::MENDING => '’s mending',
            self::ROAD => '’s road out',
            default => '’s search',
        };
    }

    /**
     * What the cards may promise while a want is known: its name, the exact
     * verbs the tick will check, and the one thing it is about when the kind
     * names one. Read into the composer's conditions, so "helps Aldan's search"
     * is quoting the very row the resolver reads.
     *
     * Null while dormant, which is the whole of the dormancy rule as it reaches
     * the cards: an undiscovered want prices nothing and promises nothing.
     *
     * @return array{name:string,verbs:list<string>,target_id:?int}|null
     */
    public static function forecast(?Scene $scene): ?array
    {
        $thread = self::shownOn($scene);

        if ($thread === null || $thread->kind === self::ROAD) {
            // The road moves on crossings, not on beats. A card that promised
            // to help it would be promising something no card can do.
            return null;
        }

        return [
            'name' => self::title($thread),
            'verbs' => $thread->kind === self::MENDING ? self::MEND_VERBS : self::REVEAL_VERBS,
            'target_id' => $thread->kind === self::MENDING ? ($thread->subject['id'] ?? null) : null,
        ];
    }

    /** The board's line: whose want, and how far along it is. */
    public static function boardLine(?Scene $scene): ?string
    {
        $thread = self::shownOn($scene);

        return $thread === null ? null
            : self::title($thread)." — {$thread->filled} of {$thread->segments}";
    }

    // -------------------------------------------------------------- attaching

    /**
     * A seeded pass over a soul the dressing just put on the ground.
     *
     * Rolled off the actor's OWN id rather than off the dressing stream — the
     * same discipline the stray roll keeps, and for the same reason: spending a
     * die per spawn inside that stream would shift every feature, inhabitant and
     * sky every dressed scene in the game has ever produced.
     *
     * Silent whenever the tale is already carrying one. That is the clocks
     * readability rule: two small stories running at once is a subplot ledger,
     * and neither of them gets noticed.
     */
    public static function attach(Actor $spawn, Scene $scene): ?Thread
    {
        if ($scene->campaign_id === null || $spawn->status !== 'active') {
            return null;
        }

        // Never a hostile, never someone already walking with them, and never
        // someone the world is already using for something else.
        if (in_array($spawn->kind, ['enemy', 'companion'], true)
            || isset($spawn->tags['offering'])
            || ($spawn->tags['following'] ?? false)) {
            return null;
        }

        $cap = max(0, (int) config('game.threads.active', 1));
        $open = Thread::where('campaign_id', $scene->campaign_id)->where('status', 'open')->count();

        if ($cap === 0 || $open >= $cap) {
            return null;
        }

        $chance = (float) config('game.threads.offer_chance', 0.12);
        $dice = new Dice($spawn->id * 2246822519 % PHP_INT_MAX);

        if ($chance <= 0 || ! $dice->chance($chance)) {
            return null;
        }

        $want = self::wantFor($scene, $dice);

        if ($want === null) {
            return null;
        }

        return Thread::create([
            'campaign_id' => $scene->campaign_id,
            'actor_id' => $spawn->id,
            'actor_name' => $spawn->name,
            'kind' => $want['kind'],
            'segments' => $want['segments'],
            'filled' => 0,
            'age' => 0,
            'revealed' => false,
            'subject' => $want['subject'],
            'status' => 'open',
            'history' => [['event' => 'attached', 'kind' => $want['kind']]],
        ]);
    }

    /**
     * What this ground gives this person to want, in fixed priority order.
     *
     * Each kind needs the scene to afford something the player could plausibly
     * help WITH — a want nobody could ever move is neglect the player never got
     * to decline, which is the one shape this feature must not take.
     *
     * @return array{kind:string,segments:int,subject:?array}|null
     */
    private static function wantFor(Scene $scene, Dice $dice): ?array
    {
        $kept = $scene->allFeatures()->first(
            fn (SceneFeature $f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
        );

        if ($kept !== null) {
            return ['kind' => self::SEEKING, 'segments' => $dice->between(2, 3), 'subject' => null];
        }

        // Standing and breakable: the thing that can still be worked on. A
        // feature already destroyed affords no card at all, so a want aimed at
        // one could only ever expire.
        $broken = $scene->visibleFeatures()->first(
            fn (SceneFeature $f) => (bool) ($f->affordances['breakable'] ?? false),
        );

        if ($broken !== null) {
            return [
                'kind' => self::MENDING,
                'segments' => $dice->between(3, 4),
                'subject' => ['type' => 'feature', 'id' => $broken->id, 'name' => $broken->name],
            ];
        }

        if ($scene->campaign?->next_zone_id !== null) {
            return ['kind' => self::ROAD, 'segments' => $dice->between(2, 3), 'subject' => null];
        }

        return null;
    }

    // -------------------------------------------------------------- resolving

    /**
     * What this ground was keeping back before the beats ran.
     *
     * A search only moves on a beat that genuinely exposed something, and the
     * only honest way to tell is to count what was concealed on both sides of
     * the turn. Read once, before anything resolves.
     *
     * @return array{concealed:int,scouted:bool}
     */
    public static function snapshot(?Scene $scene): array
    {
        if ($scene === null) {
            return ['concealed' => 0, 'scouted' => false];
        }

        $hidden = $scene->allFeatures()->filter(
            fn (SceneFeature $f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
        )->count();

        $lurking = $scene->activeActors()->filter(fn (Actor $a) => $a->tags['lurking'] ?? false)->count();

        return [
            'concealed' => $hidden + $lurking,
            'scouted' => (bool) ($scene->state['exit_scouted'] ?? false),
        ];
    }

    /**
     * One turn, measured against the open want: discovery, then help, then the
     * payoff or the bad end. Everything here reads facts this resolution has
     * already fixed — no die is cast and no legality is decided.
     *
     * At most one step per chapter, deliberately. Someone else's small story
     * moving two segments in a page would be the chapter's subject, and it is
     * never allowed to be.
     *
     * @param  list<BeatOutcome>  $outcomes
     * @param  array{concealed:int,scouted:bool}  $before
     * @return list<string>
     */
    public static function resolveTurn(Scene $scene, Turn $turn, array $outcomes, array $before): array
    {
        $thread = self::openFor($scene->campaign_id);

        if ($thread === null) {
            return [];
        }

        $actor = Actor::find($thread->actor_id);

        // The person themselves, gone. Nothing left to want.
        if ($actor === null || in_array($actor->status, self::GONE, true)) {
            return self::close($thread, 'failed');
        }

        $thread->update(['age' => (int) $thread->age + 1]);

        if (! $thread->revealed) {
            if (! self::discovered($thread, $outcomes)) {
                return self::maybeTimeOut($thread);
            }

            $thread->update([
                'revealed' => true,
                'history' => array_merge($thread->history ?? [], [['event' => 'revealed', 'turn_id' => $turn->id]]),
            ]);

            // The discovery beat is the discovery, never also the first step:
            // hearing what somebody needs is not the same as helping with it.
            return [self::revealFact($thread)];
        }

        if (self::helped($thread, $scene, $outcomes, $before)) {
            $thread->update([
                'filled' => min((int) $thread->segments, (int) $thread->filled + 1),
                'history' => array_merge($thread->history ?? [], [['event' => 'helped', 'turn_id' => $turn->id]]),
            ]);
        }

        // The road fills at the border rather than on a beat, so the finish is
        // checked from the row instead of from whatever just ticked it.
        if ((int) $thread->filled >= (int) $thread->segments) {
            $thread->update([
                'status' => 'filled',
                'history' => array_merge($thread->history ?? [], [['event' => 'filled', 'turn_id' => $turn->id]]),
            ]);

            return self::payOff($thread, $scene, $actor);
        }

        return self::maybeTimeOut($thread);
    }

    /**
     * The border. A want about this ground cannot follow the tale off it; a
     * want about the road is the one that walks, and crossing IS its progress.
     *
     * @return list<string>
     */
    public static function onSceneExit(Scene $from, Scene $to): array
    {
        $thread = self::openFor($from->campaign_id);

        if ($thread === null) {
            return [];
        }

        $actor = Actor::find($thread->actor_id);

        if ($actor === null || $actor->scene_id !== $from->id) {
            return [];
        }

        if ($thread->kind !== self::ROAD) {
            return self::close($thread, 'expired');
        }

        if ($actor->status === 'active') {
            $actor->update(['scene_id' => $to->id]);
        }

        // Ground crossed together only counts once somebody knows what it is
        // for. An undiscovered want must not be able to pay itself off.
        if ($thread->revealed) {
            $thread->update(['filled' => min((int) $thread->segments, (int) $thread->filled + 1)]);
        }

        return [];
    }

    // ------------------------------------------------------------- detection

    /**
     * Discovery: a social or inspect beat that landed on the PERSON and did not
     * simply fail. A fact, never a bonus — what it buys is knowing, and knowing
     * is what the rest of the feature is gated on.
     *
     * @param  list<BeatOutcome>  $outcomes
     */
    private static function discovered(Thread $thread, array $outcomes): bool
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->skipped || $outcome->degree === BeatOutcome::FAILURE) {
                continue;
            }
            if (($outcome->target['type'] ?? null) !== 'actor'
                || (int) ($outcome->target['id'] ?? 0) !== (int) $thread->actor_id) {
                continue;
            }

            $social = Verb::familyOf($outcome->verb) === VerbFamily::Speak
                && ! str_starts_with($outcome->verb, 'companion_');

            if ($social || in_array($outcome->verb, ['examine', 'inspect'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Help: the kind's own advance class, nothing else, and never a note.
     *
     * Seeking wants a beat that actually turned something up; mending wants
     * work done on the NAMED thing and refuses the same verb aimed anywhere
     * else; the road is not moved by beats at all.
     *
     * @param  list<BeatOutcome>  $outcomes
     * @param  array{concealed:int,scouted:bool}  $before
     */
    private static function helped(Thread $thread, Scene $scene, array $outcomes, array $before): bool
    {
        if ($thread->kind === self::ROAD) {
            return false;
        }

        $verbs = $thread->kind === self::MENDING ? self::MEND_VERBS : self::REVEAL_VERBS;
        $target = $thread->kind === self::MENDING ? ($thread->subject['id'] ?? null) : null;

        $qualified = false;

        foreach ($outcomes as $outcome) {
            if ($outcome->skipped || $outcome->degree === BeatOutcome::FAILURE
                || ! in_array($outcome->verb, $verbs, true)) {
                continue;
            }
            if ($target !== null && (($outcome->target['type'] ?? null) !== 'feature'
                || (int) ($outcome->target['id'] ?? 0) !== (int) $target)) {
                continue;
            }
            $qualified = true;
            break;
        }

        if (! $qualified || $thread->kind === self::MENDING) {
            return $qualified;
        }

        $after = self::snapshot($scene->fresh());

        return $after['concealed'] < $before['concealed']
            || ($after['scouted'] && ! $before['scouted']);
    }

    // ---------------------------------------------------------------- endings

    /**
     * The payoff, applied by the engine and nobody else. Three outcomes, all of
     * them things the engine could already do — this only decides when.
     *
     * @return list<string>
     */
    private static function payOff(Thread $thread, Scene $scene, Actor $actor): array
    {
        switch (self::PAYOFFS[$thread->kind] ?? null) {
            case self::REVELATION:
                // The sanctioned reveal: an EVENT the engine chose, which is
                // why it is allowed where the weather never is. With nothing
                // left concealed they give up the way out instead — the same
                // scouted exit a companion's scouting already opens.
                $found = $scene->allFeatures()->first(
                    fn (SceneFeature $f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
                );

                if ($found !== null) {
                    $found->update(['state' => array_merge($found->state ?? [], ['hidden' => false])]);

                    return ["{$actor->name} found what they had been looking for, and showed them {$found->name} for the trouble."];
                }

                $scene->update(['state' => array_merge($scene->state ?? [], ['exit_scouted' => true])]);

                return ["{$actor->name} found what they had been looking for, and paid it back by showing them the way out of this place."];

            case self::TOLD_TALE:
                // One row on the queue, heard later through a channel the turn
                // produces on its own. Colour, and traceable to this want: the
                // engine may never invent news, so the line is written here,
                // while the fact that earned it is still in hand.
                $what = $thread->subject['name'] ?? 'what was broken here';
                Rumors::fromThread(
                    $scene->campaign,
                    $actor->name,
                    "Word has got about of what {$actor->name} put right, and it has grown in the telling.",
                );

                return ["{$actor->name} got {$what} standing again, and told them the whole story of why it mattered."];

            case self::COMPANIONSHIP:
                // The long-promised third road, and it ends exactly where the
                // other two do: an engine-offered accept/decline pair, on the
                // next turn's cards, with the party cap untouched. At the cap
                // the offer simply does not fire — nobody is ever saddled with
                // a third, and the want still finished for THEM.
                if (Companions::atCap($scene) || Companions::offerStanding($scene) !== null) {
                    return ["{$actor->name} had come as far as they meant to, and went on from here alone."];
                }

                $actor->update(['tags' => array_merge($actor->tags ?? [], ['offering' => Companions::THREAD])]);

                return ["{$actor->name} had walked far enough beside them to say it outright, and asked to keep going."];
        }

        return [];
    }

    /**
     * The walking want, run out of chapters. The rooted kinds never reach this
     * — their ground runs out first, at the border.
     *
     * @return list<string>
     */
    private static function maybeTimeOut(Thread $thread): array
    {
        $cap = max(1, (int) config('game.threads.expiry_chapters', 8));

        if ($thread->kind !== self::ROAD || (int) $thread->age < $cap) {
            return [];
        }

        return self::close($thread, 'expired');
    }

    /**
     * It ends badly for THEM, and for nobody else.
     *
     * One plain fact, and only if the player ever knew: a want nobody
     * discovered goes quiet, because saying how it ended would be telling the
     * narrator about a story the tale never met. Nothing here touches the
     * player's sheet — no standing, no odds part, no scar. Walking past is a
     * choice about who they are, not a tax.
     *
     * @return list<string>
     */
    private static function close(Thread $thread, string $status): array
    {
        $thread->update([
            'status' => $status,
            'history' => array_merge($thread->history ?? [], [['event' => $status]]),
        ]);

        if (! $thread->revealed) {
            return [];
        }

        return [match ($thread->kind) {
            self::MENDING => "{$thread->actor_name} stopped coming back to it. Whatever it was to them, it stays as it is.",
            self::ROAD => "{$thread->actor_name} gave up waiting for company and set out alone, which was not how they wanted to go.",
            default => "{$thread->actor_name} gave up looking, and went on without whatever it was.",
        }];
    }

    private static function revealFact(Thread $thread): string
    {
        return match ($thread->kind) {
            self::MENDING => "{$thread->actor_name} said what they were doing here: something of theirs is broken, and they mean to put it right.",
            self::ROAD => "{$thread->actor_name} said what they were doing here: they are trying to get out of this country, and would rather not go alone.",
            default => "{$thread->actor_name} said what they were doing here: they are looking for something this place is keeping back.",
        };
    }

    // ------------------------------------------------------------ reading out

    /**
     * The narrator's block: what happened to somebody else's story this
     * chapter, and how near they are to what they want — in plain words and
     * never as a count. Empty while nothing has been discovered and nothing
     * happened, so an ordinary chapter carries no instructions about a
     * bystander who is simply standing there.
     */
    public static function narratorBlock(Turn $turn): string
    {
        $moments = collect($turn->resolution['thread'] ?? [])
            ->map(fn (string $fact) => "- {$fact}")->join("\n");

        $scene = $turn->scene()->first() ?? $turn->campaign?->activeScene;
        $thread = self::shownOn($scene);

        $standing = '';
        if ($thread !== null) {
            $near = match (true) {
                $thread->filled <= 0 => 'has barely begun on it',
                $thread->filled >= $thread->segments - 1 => 'is close to it now',
                default => 'is partway to it',
            };
            $standing = "- {$thread->actor_name} wants ".match ($thread->kind) {
                self::MENDING => 'something here put right',
                self::ROAD => 'out of this country, and not alone',
                default => 'something this place is keeping back',
            }.", and {$near}.\n";
        }

        if ($moments === '' && $standing === '') {
            return '';
        }

        $listed = $standing.($moments === '' ? '' : $moments."\n");

        return <<<THREAD

        ## Somebody else’s small story (fixed facts)
        {$listed}This is not the chapter's subject and must never take it over: give it a line or a short exchange inside the action, in their own voice, and move on. Never number it, never tally it, and never say how many steps are left.

        THREAD;
    }
}
