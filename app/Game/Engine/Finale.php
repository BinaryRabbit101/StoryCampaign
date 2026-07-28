<?php

namespace App\Game\Engine;

use App\Game\TurnSlot;
use App\Game\Verb;
use App\Game\VerbFamily;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Clock;
use App\Models\Grudge;
use App\Models\Scene;
use App\Models\Turn;
use App\Services\Mementos;

/**
 * A tale that ends on a peak.
 *
 * Tales used to STOP rather than conclude. The scar cap ended them and nothing
 * else did, so a keepsake book's last chapter was simply the most recent
 * chapter — a story that trails off mid-sentence. Meanwhile every system in the
 * engine had started producing climax material and nothing spent any of it: a
 * nemesis with three chapters of history, unfinished business on a clock, marks
 * that do not heal, a shelf of things carried home.
 *
 * So this is a CURATOR, not a content generator. It reads how much a tale has
 * accumulated, arms an ending, and then stages the last of it out of what the
 * tale itself built. Four disciplines hold it in shape:
 *
 *  - ARMED, NEVER FORCED. Ripeness only puts a card on the table. Declining it
 *    costs nothing, forever — the offer simply recurs, nothing escalates behind
 *    it, and the scar cap remains the only unchosen ending in the game. This
 *    engine is built on consent at every fork; the largest fork of all cannot be
 *    the one exception.
 *  - RIPENESS READS CLOSED ENGINE FACTS ONLY, and config-weighted: a chapter
 *    floor AND enough standing debt. Genre, drive, land, tech level, notes, and
 *    narration move none of it — story flexible, rules fixed, and an ending is
 *    a rule.
 *  - IT INVENTS NO MECHANICS. Ordinary cards, one odds ladder, no finale-only
 *    bonus and no finale-only penalty. Its content routes through machinery that
 *    already existed: the sanctioned grudge return (forced and telegraphing —
 *    the chapter floor and the one-per-scene rule are waived HERE and only here,
 *    which is the point of the whole system), and the existing clock when there
 *    is no old score left to settle.
 *  - THE CLOSE IS THE EXISTING CLOSE. On completion the book closes through
 *    BookCompiler::close exactly as the fall past the scar cap does — coda,
 *    flourish, compilation — and the finale adds narration GUIDANCE while
 *    underway and nothing else to that pipeline.
 *
 * And the world does not hold its breath while it runs. Pressure, the hour, the
 * air, companions, everything else keeps working: that is the difference
 * between an ending and a cutscene. The only thing suppressed is the venture
 * card, because you chose this ground.
 */
class Finale
{
    /** Ripe: the card is on the table, and will be until it is taken. */
    public const ARMED = 'armed';

    /** Taken: the target is pinned and the tale is walking its last stretch. */
    public const UNDERWAY = 'underway';

    /** Landed: the condition came due, and the book closes behind this chapter. */
    public const COMPLETE = 'complete';

    // ---- Reading where a tale stands ----

    public static function stateOf(?Campaign $campaign): ?string
    {
        $state = $campaign?->finale['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    public static function isArmed(?Campaign $campaign): bool
    {
        return self::stateOf($campaign) === self::ARMED;
    }

    public static function isUnderway(?Campaign $campaign): bool
    {
        return self::stateOf($campaign) === self::UNDERWAY;
    }

    // ---- Ripeness ----

    /**
     * How close this tale is to having earned an ending.
     *
     * Both halves must hold. The chapter floor alone gates a short tale that
     * happened to take two scars in its opening — that is a rough start, not a
     * third act. The signals alone gate a long quiet tale that never accumulated
     * anything an ending could be made of.
     *
     * Every input is a closed engine fact this campaign already holds. Nothing
     * a player typed and nothing Claude wrote appears anywhere in it.
     *
     * @return array{chapters:int,floor:int,signals:int,threshold:int,parts:array<string,int>,ripe:bool}
     */
    public static function ripeness(Campaign $campaign): array
    {
        $weights = (array) config('game.finale.weights', []);
        $weight = fn (string $key) => (int) ($weights[$key] ?? 0);

        $parts = [];

        // A nemesis at full heat. Counted ONCE however many there are: a tale
        // with one already has its last scene written, and a second does not
        // make it any more written.
        $parts['max_heat_grudge'] = Grudge::where('campaign_id', $campaign->id)
            ->where('status', '!=', 'resolved')
            ->where('heat', '>=', Grudges::MAX_HEAT)->exists() ? $weight('max_heat_grudge') : 0;

        // Business seen all the way through — the player's own, never the
        // engine's own last-stretch clock.
        $parts['clock_filled'] = $weight('clock_filled') * Clock::where('campaign_id', $campaign->id)
            ->whereNotIn('kind', Clocks::ENGINE_KINDS)
            ->where('status', 'filled')->count();

        // Ground crossed, read off the scenes actually stood in rather than the
        // zones forged: a pre-forged frontier nobody walked into is not country
        // this tale has been to.
        $zones = Scene::where('campaign_id', $campaign->id)->distinct()->count('zone_id');
        $parts['zone_beyond_first'] = $weight('zone_beyond_first') * max(0, $zones - 1);

        $parts['scar'] = $weight('scar') * ($campaign->character === null
            ? 0 : Scars::count($campaign->character));

        // The shelf, in fours. It fills on its own as a tale runs, so counting
        // every keepsake would arm every tale by attrition.
        $per = max(1, (int) config('game.finale.mementos_per_signal', 4));
        $parts['mementos'] = $weight('mementos') * intdiv(Mementos::count($campaign), $per);

        $chapters = (int) $campaign->chapters()->count();
        $floor = (int) config('game.finale.chapter_floor', 8);
        $threshold = (int) config('game.finale.arm_threshold', 4);
        $signals = array_sum($parts);

        return [
            'chapters' => $chapters,
            'floor' => $floor,
            'signals' => $signals,
            'threshold' => $threshold,
            'parts' => $parts,
            'ripe' => $chapters >= $floor && $signals >= $threshold,
        ];
    }

    // ---- The per-resolution check ----

    /**
     * Weighed once per resolution, off facts the turn has already fixed.
     *
     * Three things can happen here and no more: a ripe tale ARMS, an armed tale
     * whose player took the card BEGINS, and everything else is silence. An
     * armed tale that was declined stays exactly as armed as it was — there is
     * no counter behind this, nothing sharpens, and no second offer is worse
     * than the first.
     *
     * @param  list<BeatOutcome>  $outcomes
     * @return array{event:string,state:string,subject:?string,facts:list<string>,complete:bool}|null
     */
    public static function consider(Campaign $campaign, Scene $scene, Turn $turn, Dice $dice, array $outcomes): ?array
    {
        $state = self::stateOf($campaign);

        if ($state === self::UNDERWAY || $state === self::COMPLETE) {
            return null;
        }

        if ($state === self::ARMED) {
            return self::taken($outcomes) ? self::begin($campaign, $scene, $turn, $dice) : null;
        }

        return self::ripeness($campaign)['ripe'] ? self::arm($campaign) : null;
    }

    /** Did the player take the offer up this turn? A failed beat never does. */
    private static function taken(array $outcomes): bool
    {
        return collect($outcomes)->contains(
            fn (BeatOutcome $o) => ! $o->skipped
                && $o->verb === Verb::Face->value
                && $o->degree !== BeatOutcome::FAILURE,
        );
    }

    /** @return array{event:string,state:string,subject:?string,facts:list<string>,complete:bool} */
    private static function arm(Campaign $campaign): array
    {
        $ripeness = self::ripeness($campaign);

        $campaign->update(['finale' => [
            'state' => self::ARMED,
            'signals' => $ripeness['signals'],
            'armed_at_chapter' => $ripeness['chapters'],
        ]]);

        // No facts. Arming changes nothing in the world — it puts a card on the
        // table and a line on the board — so the chapter that happens to be the
        // one it armed in carries no instructions about it.
        return [
            'event' => 'armed',
            'state' => self::ARMED,
            'subject' => self::previewName($campaign),
            'facts' => [],
            'complete' => false,
        ];
    }

    /**
     * The ending, taken up. The target is pinned here and never moves again:
     * the hottest simmering score, by heat and then by age, or — for a tale with
     * no old score left — the engine's own last-stretch clock.
     *
     * @return array{event:string,state:string,subject:?string,facts:list<string>,complete:bool}
     */
    private static function begin(Campaign $campaign, Scene $scene, Turn $turn, Dice $dice): array
    {
        $grudge = self::pin($campaign);

        $record = [
            'state' => self::UNDERWAY,
            'signals' => (int) ($campaign->finale['signals'] ?? 0),
            'grudge_name' => $grudge?->actor_name,
            'clock_id' => null,
        ];

        if ($grudge === null) {
            $clock = self::mintReckoning($campaign);
            $record['clock_id'] = $clock->id;
            $facts = [
                'They stopped putting it off, and set themselves to finishing what this road had been building toward.',
            ];
        } else {
            $facts = [
                "They stopped waiting on {$grudge->actor_name} to choose the ground, and went looking for them instead.",
            ];
        }

        $campaign->update(['finale' => $record]);

        // Here, if the ground allows it. A nemesis who walks in on a fight
        // already in progress is one more enemy; the last arrival has to be able
        // to be the only thing standing there. Otherwise they come at the first
        // change of ground, which is what `arrive` is for.
        if ($grudge !== null && ! self::contested($scene)) {
            $came = self::summon($campaign, $scene, $dice, $turn);
            $facts = array_merge($facts, $came);
        }

        return [
            'event' => 'begun',
            'state' => self::UNDERWAY,
            'subject' => $grudge?->actor_name,
            'facts' => $facts,
            'complete' => false,
        ];
    }

    /**
     * What the tale's last scene is made of, if it has a face.
     *
     * Hottest first, and on a tie the oldest score in the tale: the one that has
     * been out there longest is the one the ending owes.
     */
    private static function pin(Campaign $campaign): ?Grudge
    {
        return Grudge::where('campaign_id', $campaign->id)
            ->where('status', 'simmering')
            ->orderByDesc('heat')->orderBy('id')->first();
    }

    /** Is somebody already standing in the open here? */
    private static function contested(Scene $scene): bool
    {
        return $scene->fresh()?->visibleActors()
            ->contains(fn (Actor $a) => $a->kind === 'enemy') ?? false;
    }

    // ---- The last stretch ----

    /**
     * One beat, measured against the engine's own clock — by exactly the rule
     * an endeavor moves by, read out of Clocks so there is one copy of it. A
     * tale whose ending has a face never has one of these at all.
     */
    public static function advance(?Campaign $campaign, BeatOutcome $outcome): void
    {
        $clock = self::reckoning($campaign);

        if ($clock === null || ! Clocks::qualifies($clock, $outcome)) {
            return;
        }

        $filled = min((int) $clock->segments, (int) $clock->filled + 1);

        $clock->update([
            'filled' => $filled,
            'status' => $filled >= (int) $clock->segments ? 'filled' : 'open',
        ]);
    }

    /**
     * Everything the last stretch does after the ground has settled: the
     * nemesis brought back at the first change of ground, and the ending that
     * lands when the condition the finale pinned comes due.
     *
     * Runs after Grudges::settle on purpose — that is where a score becomes
     * resolved, and asking before it would be asking a question whose answer has
     * not been written yet.
     *
     * @param  array|null  $record  What this turn has already said about the
     *                              finale, if anything. Returned merged.
     */
    public static function step(Campaign $campaign, Turn $turn, ?array $record, Scene $before, Scene $after, Dice $dice): ?array
    {
        if (! self::isUnderway($campaign)) {
            return $record;
        }

        $arrived = $after->id === $before->id ? [] : self::summon($campaign, $after, $dice, $turn);

        if ($arrived !== []) {
            $record = self::merge($record, [
                'event' => 'arrival',
                'state' => self::UNDERWAY,
                'subject' => $campaign->finale['grudge_name'] ?? null,
                'facts' => $arrived,
                'complete' => false,
            ]);
        }

        return self::merge($record, self::complete($campaign, $after));
    }

    /**
     * Bring the pinned score back onto this ground, forced and telegraphing.
     * Silent when there is no score pinned, when they are already standing here,
     * or when they are mid-return somewhere else.
     *
     * @return list<string>
     */
    private static function summon(Campaign $campaign, Scene $scene, Dice $dice, Turn $turn): array
    {
        $name = $campaign->finale['grudge_name'] ?? null;

        if (! is_string($name) || self::present($scene, $name)) {
            return [];
        }

        $actor = Grudges::forceReturn($campaign, $name, $scene, $dice, $turn);

        return $actor === null ? [] : [
            "{$actor->name} came for them at {$scene->title}, openly and with nothing else left to say.",
        ];
    }

    private static function present(Scene $scene, string $name): bool
    {
        return $scene->actors()->where('name', $name)
            ->whereIn('status', ['active', 'restrained'])->exists();
    }

    /**
     * Has the ending landed?
     *
     * An engine CONDITION, never a judgement: the pinned score is resolved —
     * killed, kept, or bargained out, all three of which the grudge machinery
     * already records — or the last-stretch clock is full. Nothing here reads
     * how the chapter felt.
     *
     * @return array{event:string,state:string,subject:?string,facts:list<string>,complete:bool}|null
     */
    private static function complete(Campaign $campaign, Scene $scene): ?array
    {
        $finale = $campaign->finale ?? [];
        $name = $finale['grudge_name'] ?? null;
        $place = $scene->title;

        if (is_string($name)) {
            $grudge = Grudge::where('campaign_id', $campaign->id)
                ->where('actor_name', $name)->first();

            if ($grudge?->status !== 'resolved') {
                return null;
            }

            $facts = [
                "It ended at {$place}. Whatever was owed between them and {$name} is not owed any more.",
                'There is nothing else out there with their name on it.',
            ];
        } else {
            $clock = Clock::find($finale['clock_id'] ?? 0);

            if ($clock === null || $clock->status !== 'filled') {
                return null;
            }

            $facts = [
                "The last of it was finished at {$place}.",
                'What this road had been building toward is behind them now, and there is nothing further to see through.',
            ];
        }

        $campaign->update(['finale' => array_merge($finale, ['state' => self::COMPLETE])]);

        return [
            'event' => 'complete',
            'state' => self::COMPLETE,
            'subject' => is_string($name) ? $name : null,
            'facts' => $facts,
            'complete' => true,
        ];
    }

    /**
     * Two records from one resolution, folded into one. The later event names
     * the record; the facts accumulate in the order they happened.
     */
    private static function merge(?array $first, ?array $second): ?array
    {
        if ($first === null || $second === null) {
            return $second ?? $first;
        }

        return array_merge($second, [
            'facts' => array_merge($first['facts'], $second['facts']),
        ]);
    }

    // ---- What the engine's own clock is made of ----

    /** The tale's last-stretch clock, while it is running. */
    public static function reckoning(?Campaign $campaign): ?Clock
    {
        if ($campaign === null) {
            return null;
        }

        return Clock::where('campaign_id', $campaign->id)
            ->where('kind', Clocks::RECKONING)
            ->where('status', 'open')->orderBy('id')->first();
    }

    private static function mintReckoning(Campaign $campaign): Clock
    {
        return Clock::create([
            'campaign_id' => $campaign->id,
            // The tale's, not the ground's: it travels every border, and
            // Clocks::on — which is about what stands on THIS ground — never
            // sees it, so the abandon card can never be offered against it.
            'scene_id' => null,
            'kind' => Clocks::RECKONING,
            'name' => 'the last of it',
            'segments' => (int) config('game.clocks.max_segments', 6),
            'filled' => 0,
            'advance_verbs' => self::advanceVerbs($campaign),
            'payoff' => Clocks::NO_PAYOFF,
            'subject' => null,
            'portable' => true,
            'status' => 'open',
        ]);
    }

    /**
     * What moves the last stretch: the way this player has actually been
     * playing.
     *
     * Read off resolved turns — the beats the tale already cast dice for — and
     * tallied by family rather than by verb, so a tale of climbing and running
     * ends in a stretch of climbing and running rather than in whichever single
     * verb happened to come up most. The two strongest families, and improvise
     * on top of them always: it is offered on every ground there is, so the
     * ending can never be made unreachable by a scene that affords nothing else.
     *
     * @return list<string>
     */
    public static function advanceVerbs(Campaign $campaign): array
    {
        $tally = [];

        foreach ($campaign->turns()->where('status', Turn::STATUS_COMPLETE)->get() as $turn) {
            foreach ($turn->resolution['beats'] ?? [] as $beat) {
                $verb = $beat['verb'] ?? null;

                if (($beat['skipped'] ?? false) || ! is_string($verb) || ! Odds::rolls($verb)) {
                    continue;
                }

                $family = Verb::familyOf($verb)->value;
                $tally[$family] = ($tally[$family] ?? 0) + 1;
            }
        }

        // Count first, then family name: a tie must resolve the same way every
        // time this is asked, or a re-resolved turn would mint a different clock.
        uksort($tally, fn (string $a, string $b) => [$tally[$b], $a] <=> [$tally[$a], $b]);

        $verbs = collect(array_slice(array_keys($tally), 0, 2))
            ->flatMap(fn (string $family) => VerbFamily::from($family)->verbs())
            ->map(fn (Verb $verb) => $verb->value)
            ->reject(fn (string $verb) => ! Odds::rolls($verb) || str_starts_with($verb, 'companion_'))
            ->push(Verb::Improvise->value)
            ->unique()->values()->all();

        return $verbs;
    }

    // ---- What the player, the composer and the narrator are told ----

    /**
     * The offer. Main slot, roll-free, and it says what it is: there is no
     * stepping back from this one, and the card has to say so before it is
     * chosen — the bargain rule, applied to structure.
     *
     * @return list<ActionCard>
     */
    public static function cards(?Campaign $campaign): array
    {
        if (! self::isArmed($campaign)) {
            return [];
        }

        $name = self::previewName($campaign);

        $label = $name === null
            ? 'Go looking for the end of this'
            : "Go after {$name}, and finish it";

        $opening = $name === null
            ? 'Everything this road has been gathering comes due, and you go to meet it instead of waiting on it.'
            : "You stop letting {$name} choose the ground and the hour, and go to them.";

        return [new ActionCard(
            slot: TurnSlot::Main,
            verb: Verb::Face->value,
            label: $label,
            description: $opening.' Taking this begins the ending of this tale, and there is no stepping back from it: '
                .'the road narrows from here, and it does not widen again.',
            target: ['type' => 'finale', 'id' => null, 'name' => $name ?? 'the last of it'],
        )];
    }

    /** Who the card names — the same pin the beginning will make, read early. */
    private static function previewName(Campaign $campaign): ?string
    {
        return self::pin($campaign)?->actor_name;
    }

    /**
     * The board's line. Plain words and no numbers: armed says an ending is
     * available and nothing more, because "signals 5 of 4" would turn the
     * gathering of a tale into a progress bar.
     */
    public static function boardLine(?Campaign $campaign): ?string
    {
        $name = $campaign?->finale['grudge_name'] ?? null;

        return match (self::stateOf($campaign)) {
            self::ARMED => 'This tale is gathering toward its end. There is a way to meet it whenever you are ready, and nothing forces it.',
            self::UNDERWAY => is_string($name)
                ? "This is the last of it: you and {$name}, and no more waiting on it."
                : 'This is the last of it: what the road has been building toward, and no more putting it off.',
            default => null,
        };
    }

    /**
     * The narrator's guidance while the last stretch runs, and the facts of the
     * ending when it lands. Plain words, no mechanics, and empty on every
     * chapter of every tale that has not taken its ending up — an ordinary
     * chapter carries no instructions about an ending.
     */
    public static function narratorBlock(Turn $turn): string
    {
        $campaign = $turn->campaign;
        $record = $turn->resolution['finale'] ?? null;
        $state = $record['state'] ?? self::stateOf($campaign);

        if ($state !== self::UNDERWAY && $state !== self::COMPLETE) {
            return '';
        }

        $facts = collect($record['facts'] ?? [])->map(fn (string $fact) => "- {$fact}")->join("\n");
        $facts = $facts === '' ? '' : "{$facts}\n";

        if ($state === self::COMPLETE) {
            return <<<END

            ## The end of this tale (fixed facts — this is what the chapter is about)
            {$facts}
            Write the last of it and let it come to rest. Give the ending the room it has earned: this is the moment everything behind it was walking toward, and the page should feel it arrive rather than be told that it has. Then stop — quietly, inside the scene, with nobody promising what comes next. Do not name it as an ending, do not address the reader, and do not begin anything new.

            END;
        }

        return <<<CLOSING

        ## The closing movement (fixed fact)
        {$facts}This tale is in its last stretch, and both the character and the reader can feel it. Write toward rest: let the chapter narrow rather than widen, let what is already standing come to a head instead of introducing something that would need a whole tale of its own, and let the character move like someone who has stopped saving anything for later. Never say that the story is ending and never address the reader — the narrowing is something the prose does, not something it announces.

        CLOSING;
    }
}
