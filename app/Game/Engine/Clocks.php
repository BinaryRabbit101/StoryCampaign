<?php

namespace App\Game\Engine;

use App\Game\TurnSlot;
use App\Game\Verb;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Clock;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;

/**
 * Endeavor clocks: the goal the player is working toward, priced like a card.
 *
 * Every tension the game had before this was scene-local — a telegraph, an
 * angle, the alarm, a lurker, a pursuit — and all of it reset the moment the
 * ground changed. Nothing persisted that the player was working FOR, so the
 * idle wait had nothing pulling them back through it except the next paragraph.
 *
 * An endeavor is the alarm clock turned around. The alarm counts up against
 * the player while they stand and fight; this counts up FOR them while they do
 * the things the scene was already asking of them.
 *
 * Four disciplines hold it in shape:
 *
 *  - The ENGINE authors it. Clocks are offered as cards when the ground
 *    supports one — never named, never free text — and the whole proposal is
 *    recomputed from the scene at commit time rather than carried on the card,
 *    so nothing arbitrary can ride in on a submission.
 *  - Progress is MECHANICAL. A beat moves it only when its verb is in the
 *    clock's own `advance_verbs` and the die did not simply fail. Notes,
 *    genre, land, and narration move nothing.
 *  - The payoff is a CLOSED enum applied by the engine, and each of the three
 *    routes through machinery that already existed: the sanctioned reveal, a
 *    feature's destroyed state, and one existing Odds::CONDITIONS condition.
 *    There is no parallel buff here and there never may be.
 *  - It is never free and never a trap. Committing costs a whole main beat;
 *    the ticks after it ride on beats the player was taking anyway, which is
 *    exactly why the payoffs stay modest. Giving it up costs only a post beat
 *    and buys back the right to take up something else.
 *
 * Note on Pressure: committing is a QUIET verb (no die — a declaration is not
 * something a roll can adjudicate), so a turn spent taking on an endeavor is a
 * quiet turn and the stall counter fills. That is correct and deliberate:
 * standing on empty ground announcing an intention is precisely the stillness
 * the world is entitled to answer. The two systems need no coupling code.
 */
class Clocks
{
    /** Ground that is keeping several things back: sweep it properly. */
    public const SEARCH = 'search';

    /** Something standing in the way that can be taken apart, given time. */
    public const DEMOLITION = 'demolition';

    /** A fight already under way, worked at until they are set for it. */
    public const PREPARATION = 'preparation';

    /**
     * The tale's own last stretch, minted by App\Game\Engine\Finale when a
     * campaign takes up its ending and has no old score left to settle.
     *
     * The ENGINE owns it end to end: it is never proposed, never committed to,
     * and never abandoned — which is exactly why it is exempt from the
     * one-at-a-time rule below without freeing (or occupying) the player's own
     * slot. A finale a player's half-finished search could deadlock is a finale
     * that does not work, and charging them their endeavor to be allowed an
     * ending would be the engine taxing the last scene of the book.
     */
    public const RECKONING = 'reckoning';

    /** @var list<string> Kinds the player never took on, and cannot set down. */
    public const ENGINE_KINDS = [self::RECKONING];

    /**
     * What an engine-owned clock pays out: nothing. Its payoff is the thing it
     * is counting toward, which happens outside this class entirely.
     */
    public const NO_PAYOFF = 'none';

    /** The closed payoff list. Each one routes through machinery that existed. */
    public const REVEAL_HIDDEN = 'reveal_hidden';

    public const DESTROY_OBSTACLE = 'destroy_obstacle';

    public const GRANT_READIED = 'grant_readied';

    /** How many hidden things make a place worth searching properly. */
    private const SEARCH_THRESHOLD = 2;

    /**
     * The open endeavor standing on this ground, if there is one.
     *
     * Scoped to the scene as well as the campaign: a portable clock is moved
     * onto the new ground at the transition and a scene-scoped one expires
     * there, so an open clock is always about the place the player is in.
     */
    public static function on(?Scene $scene): ?Clock
    {
        if ($scene === null || $scene->campaign_id === null) {
            return null;
        }

        return Clock::where('campaign_id', $scene->campaign_id)
            ->where('scene_id', $scene->id)
            ->whereNotIn('kind', self::ENGINE_KINDS)
            ->where('status', 'open')
            ->orderBy('id')->first();
    }

    /**
     * The one endeavor this tale may have running at a time, wherever it
     * stands. Engine-owned kinds are not the player's and never count toward
     * it — see the RECKONING note above.
     */
    public static function openFor(Campaign $campaign): ?Clock
    {
        return Clock::where('campaign_id', $campaign->id)
            ->whereNotIn('kind', self::ENGINE_KINDS)
            ->where('status', 'open')->orderBy('id')->first();
    }

    /**
     * The cards this endeavor system puts on the table — at most two, and
     * usually none.
     *
     * The offer is occasional by construction: one endeavor per tale at a time,
     * at most one ever proposed per scene (a clock row of any status on this
     * ground closes the door), and a seeded chance on top of that so the same
     * ground does not open with the same invitation every single turn.
     *
     * @return list<ActionCard>
     */
    public static function cards(Scene $scene, Dice $dice): array
    {
        $open = self::on($scene);

        if ($open !== null) {
            return [self::abandonCard($open)];
        }

        if (! self::mayOffer($scene)) {
            return [];
        }

        $proposal = self::propose($scene);

        // Nothing eligible costs no die: a scene with no honest endeavor in it
        // must not shift the stream for the turns that follow.
        if ($proposal === null || ! $dice->chance((float) config('game.clocks.offer_chance', 0.5))) {
            return [];
        }

        return [self::commitCard($proposal)];
    }

    /**
     * Is this ground allowed to propose one at all? One endeavor per tale, and
     * one proposal per scene however it ended — a place the player already
     * turned down (or finished) does not keep asking.
     */
    public static function mayOffer(Scene $scene): bool
    {
        if ($scene->campaign_id === null) {
            return false;
        }

        return ! Clock::where('campaign_id', $scene->campaign_id)
            ->whereNotIn('kind', self::ENGINE_KINDS)
            ->where(fn ($q) => $q->where('status', 'open')->orWhere('scene_id', $scene->id))
            ->exists();
    }

    /**
     * What this ground affords, in fixed priority order. Recomputed at commit
     * time as well as at offer time: the card carries the words, the scene
     * carries the terms, and a scene that changed under the offer simply has
     * nothing left to take on.
     *
     * @return array{kind:string,name:string,label:string,description:string,segments:int,advance_verbs:list<string>,payoff:string,portable:bool,subject:?array}|null
     */
    public static function propose(Scene $scene): ?array
    {
        $hidden = $scene->allFeatures()->filter(
            fn (SceneFeature $f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
        );

        if ($hidden->count() >= self::SEARCH_THRESHOLD) {
            return self::clamp([
                'kind' => self::SEARCH,
                'name' => "the search of {$scene->title}",
                'label' => "Begin a proper search of {$scene->title}",
                'description' => "This place is holding more than one thing back. Set yourself to going over it end to end — reading the ground and hunting what is wrong here will carry it, and finishing it turns out everything {$scene->title} is keeping.",
                'segments' => 5,
                'advance_verbs' => [Verb::Scout->value, Verb::Detect->value, Verb::Improvise->value],
                'payoff' => self::REVEAL_HIDDEN,
                'portable' => false,
                'subject' => null,
            ]);
        }

        $obstacle = $scene->visibleFeatures()->first(
            fn (SceneFeature $f) => ($f->affordances['breakable'] ?? false) && ! ($f->state['destroyed'] ?? false),
        );

        if ($obstacle !== null) {
            return self::clamp([
                'kind' => self::DEMOLITION,
                'name' => "the breaking of {$obstacle->name}",
                'label' => "Set about breaking {$obstacle->name}",
                'description' => "{$obstacle->name} will not go in one blow, but it will go. Work at it across several beats — every honest attempt on it counts — and when the work is done it is down for good.",
                'segments' => 4,
                'advance_verbs' => [Verb::Break->value, Verb::Improvise->value],
                'payoff' => self::DESTROY_OBSTACLE,
                'portable' => false,
                'subject' => ['type' => 'feature', 'id' => $obstacle->id, 'name' => $obstacle->name],
            ]);
        }

        $fight = $scene->visibleActors()->contains(fn (Actor $a) => $a->kind === 'enemy');

        if ($fight) {
            return self::clamp([
                'kind' => self::PREPARATION,
                'name' => 'getting the measure of this fight',
                'label' => 'Set yourself to reading this fight out',
                'description' => 'Stop swinging blind. Every blow you land or turn from here teaches you something about them, and once you have their measure you are set and waiting for whatever comes next.',
                'segments' => 4,
                'advance_verbs' => [
                    Verb::Strike->value, Verb::Interrupt->value,
                    Verb::Restrain->value, Verb::Improvise->value,
                ],
                'payoff' => self::GRANT_READIED,
                // What the body learned travels with the body. The only
                // endeavor that survives a change of ground.
                'portable' => true,
                'subject' => null,
            ]);
        }

        return null;
    }

    /**
     * Taking it on. Roll-free — a declaration is not something a die can
     * adjudicate, and a "failed" commitment would be the engine telling the
     * player their decision did not take.
     *
     * @return list<string>
     */
    public static function commit(Scene $scene): array
    {
        $campaign = $scene->campaign;

        if ($campaign === null || self::openFor($campaign) !== null) {
            return ['There was already something they were seeing through, and it had their attention.'];
        }

        $proposal = self::propose($scene);

        if ($proposal === null) {
            return ['Whatever they had meant to set about here was no longer there to set about.'];
        }

        Clock::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $scene->id,
            'kind' => $proposal['kind'],
            'name' => $proposal['name'],
            'segments' => $proposal['segments'],
            'filled' => 0,
            'advance_verbs' => $proposal['advance_verbs'],
            'payoff' => $proposal['payoff'],
            'subject' => $proposal['subject'],
            'portable' => $proposal['portable'],
            'status' => 'open',
        ]);

        return ["They set themselves to {$proposal['name']}, and meant to see it through."];
    }

    /**
     * Letting it go. Free of everything except the beat it is spoken in, and
     * never a dead choice: what it buys back is the right to take up something
     * else, which one endeavor at a time is otherwise holding shut.
     *
     * @return list<string>
     */
    public static function abandon(Scene $scene, ?int $clockId): array
    {
        $clock = self::on($scene);

        if ($clock === null || ($clockId !== null && $clock->id !== $clockId)) {
            return ['There was nothing of theirs left half-done here.'];
        }

        $clock->update(['status' => 'abandoned']);

        return ["They let {$clock->name} go unfinished, and turned their attention elsewhere."];
    }

    /**
     * One beat, measured against the open endeavor.
     *
     * A partial counts. That is the deliberate reading: this counts GROUND
     * COVERED, not blows landed, and the movement verbs already treat a
     * partial as ground genuinely crossed ("they got through, and it cost
     * them"). A clock that only moved on clean successes would sit at one of
     * five through a whole scene and teach the player that committing was a
     * mistake. A failure moves nothing, and neither does a skipped beat or a
     * verb the clock never named.
     *
     * @param  array<string,mixed>  $conditions  The live set for this resolution.
     *                                           The readiness payoff lands in it — the same condition
     *                                           `ready` and the tend-gear stance grant, priced once in
     *                                           Odds::CONDITIONS and nowhere else.
     * @return array{facts:list<string>,filled:?string}
     */
    public static function advance(Scene $scene, BeatOutcome $outcome, array &$conditions): array
    {
        $nothing = ['facts' => [], 'filled' => null];

        $clock = self::on($scene);

        if ($clock === null || ! self::qualifies($clock, $outcome)) {
            return $nothing;
        }

        $filled = min($clock->segments, $clock->filled + 1);

        if ($filled < $clock->segments) {
            $clock->update(['filled' => $filled]);

            return $nothing;
        }

        $clock->update(['filled' => $filled, 'status' => 'filled']);

        return [
            'facts' => self::payOff($clock, $scene, $conditions),
            'filled' => $clock->name,
        ];
    }

    /**
     * Does this beat move that clock?
     *
     * The rule, in one place, because more than one clock reads it: the beat
     * has to have happened, it has to be one the clock named, and the die must
     * not have simply failed. A partial counts — this measures ground covered,
     * not blows landed.
     *
     * A beat that cast no die cannot pay for progress either: quiet verbs
     * always "succeed", so counting them would make any clock free. Read off
     * the outcome as well as off the verb, because one verb is certain only
     * sometimes — an uncontested step through a door is free the same way a
     * quiet beat is, and the card it was chosen from promised no progress.
     */
    public static function qualifies(Clock $clock, BeatOutcome $outcome): bool
    {
        if ($outcome->skipped || $outcome->degree === BeatOutcome::FAILURE) {
            return false;
        }

        return Odds::rolls($outcome->verb)
            && $outcome->castADie()
            && in_array($outcome->verb, $clock->advance_verbs, true);
    }

    /**
     * The payoff, applied by the engine and nobody else. Three outcomes, all
     * of them things the engine could already do — this only decides WHEN.
     *
     * @param  array<string,mixed>  $conditions
     * @return list<string>
     */
    private static function payOff(Clock $clock, Scene $scene, array &$conditions): array
    {
        $facts = ["The work of {$clock->name} was finished."];

        switch ($clock->payoff) {
            case self::REVEAL_HIDDEN:
                // The sanctioned reveal: an EVENT the engine chose, which is
                // why it is allowed where the weather never is.
                $found = [];
                foreach ($scene->allFeatures() as $feature) {
                    if (($feature->state['hidden'] ?? false) && ! ($feature->state['destroyed'] ?? false)) {
                        $feature->update(['state' => array_merge($feature->state ?? [], ['hidden' => false])]);
                        $found[] = $feature->name;
                    }
                }
                $facts[] = $found === []
                    ? 'This place had nothing left to give up by then — it had all been turned out already.'
                    : 'Gone over end to end, this place gave up everything it had been holding back: '.implode(', ', $found).'.';
                break;

            case self::DESTROY_OBSTACLE:
                $obstacle = SceneFeature::find($clock->subject['id'] ?? 0);
                if ($obstacle === null || ($obstacle->state['destroyed'] ?? false)) {
                    $facts[] = 'What they had been working at was already gone by the time the work was done.';
                    break;
                }
                $obstacle->update(['state' => array_merge($obstacle->state ?? [], ['destroyed' => true])]);
                $facts[] = "{$obstacle->name} came apart under the last of it, and is not in anyone's way any more.";
                break;

            case self::GRANT_READIED:
                // The existing condition, never a parallel buff: a second
                // "+2 when set" living outside the ledger is how a card's
                // printed odds start drifting from the dice.
                $conditions['readied'] = true;
                $facts[] = 'They had the measure of it now, and stood set and waiting for whatever came next.';
                break;
        }

        return $facts;
    }

    /**
     * Scene exit. Ground-bound endeavors die with their ground — a goal about
     * a place the tale has left is a goal that can never be finished, and a
     * board line about it would be a promise the engine cannot keep. What the
     * body learned comes along.
     */
    public static function onSceneExit(Scene $before, Scene $after): void
    {
        $clock = self::on($before);

        if ($clock === null) {
            return;
        }

        $clock->update($clock->portable
            ? ['scene_id' => $after->id]
            : ['status' => 'expired']);
    }

    /**
     * What the cards may promise: the open endeavor's name and the exact verb
     * list the tick will check. Read into the composer's conditions, so a card
     * saying "advances the search of the long quay" is quoting the very row
     * the resolver reads — one source, two readers, no drift.
     *
     * @return array{name:string,verbs:list<string>}|null
     */
    public static function forecast(?Scene $scene): ?array
    {
        $clock = self::on($scene);

        return $clock === null ? null : [
            'name' => $clock->name,
            'verbs' => array_values($clock->advance_verbs),
        ];
    }

    /** The board's line: the goal, and how far along it is, in plain words. */
    public static function boardLine(?Scene $scene): ?string
    {
        $clock = self::on($scene);

        return $clock === null ? null : "{$clock->name} — {$clock->filled} of {$clock->segments}";
    }

    /** The pips beside the meters: what is under way, and how far. @return array{name:string,filled:int,segments:int}|null */
    public static function page(Campaign $campaign): ?array
    {
        $clock = self::openFor($campaign);

        return $clock === null ? null : [
            'name' => $clock->name,
            'filled' => (int) $clock->filled,
            'segments' => (int) $clock->segments,
        ];
    }

    /**
     * The narrator's block: what was finished this chapter, or what is still
     * being worked at — as a plain goal, never a count. Empty on every chapter
     * with no endeavor in it, so an ordinary page carries no instructions
     * about one.
     */
    public static function narratorBlock(Turn $turn): string
    {
        $finished = collect($turn->resolution['endeavor'] ?? [])
            ->map(fn (string $fact) => "- {$fact}")->join("\n");

        if ($finished !== '') {
            return <<<DONE

            ## The thing they had set themselves to, finished (fixed facts)
            {$finished}
            This is the chapter's own payoff: write the moment the work comes good, once, inside the action. Never as a tally or a status.

            DONE;
        }

        $campaign = $turn->campaign;
        $clock = $campaign === null ? null : self::openFor($campaign);

        if ($clock === null) {
            return '';
        }

        return <<<GOAL

        ## What they are partway through (fixed fact)
        They are partway through {$clock->name}, and have not finished it.
        Let it show in what they do and what they are watching for. Never count it, never number it, and never say it is done.

        GOAL;
    }

    /** The card that takes it on. Roll-free, and it costs the whole main beat. */
    private static function commitCard(array $proposal): ActionCard
    {
        return new ActionCard(
            slot: TurnSlot::Main,
            verb: Verb::Undertake->value,
            label: $proposal['label'],
            description: $proposal['description'].' It takes '
                .$proposal['segments'].' beats that count, and this one is not one of them.',
            target: ['type' => 'endeavor', 'id' => null, 'name' => $proposal['name']],
        );
    }

    /** The card that lets it go. Free of everything but the beat it is said in. */
    private static function abandonCard(Clock $clock): ActionCard
    {
        return new ActionCard(
            slot: TurnSlot::Post,
            verb: Verb::Abandon->value,
            label: "Give up on {$clock->name}",
            description: 'Everything already done toward it is lost, and nothing is charged for walking away. What it buys back is room to take something else on.',
            target: ['type' => 'endeavor', 'id' => $clock->id, 'name' => $clock->name],
        );
    }

    /** Segment counts stay inside the configured band, whatever a proposal asks for. */
    private static function clamp(array $proposal): array
    {
        $proposal['segments'] = min(
            (int) config('game.clocks.max_segments', 6),
            max((int) config('game.clocks.min_segments', 4), (int) $proposal['segments']),
        );

        return $proposal;
    }
}
