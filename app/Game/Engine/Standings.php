<?php

namespace App\Game\Engine;

use App\Models\Scene;
use App\Models\Standing;
use App\Models\Turn;

/**
 * Places remember what you did there.
 *
 * Grudges gave the tale's ENEMIES a memory of the player. Nothing gave the
 * GROUND one: a zone where captives were cut loose and a zone where the well
 * was wrecked greeted the character in exactly the same words. Standing is the
 * communal mirror of the grudge — one clamped number per campaign per zone,
 * moved only by facts the resolver had already fixed, appended to a history
 * the narrator may quote, and read back as plain words.
 *
 * Three disciplines hold it in shape:
 *
 *  - It is a LISTENER, not an event source. Every entry in the table below is
 *    something the resolution already decided; nothing here rolls, nothing here
 *    spawns, and nothing here asks the player for anything.
 *  - ZERO EMITS NOTHING. No board line, no odds part, no fact, no narrator
 *    block. An unknown is unknown, and most ground is unknown — a bullet
 *    saying so every turn is noise wearing a title.
 *  - The MECHANICAL half lives in Odds::STANDING, one small itemized part on
 *    the social verbs only. One ladder, two readers, so a card's forecast and
 *    the die it is measured against can never disagree.
 *
 * What is deliberately absent: the evolver. Standing is not tended off-screen
 * this round — a world that quietly forgave you overnight would make the whole
 * ledger a matter of how long you left the app closed. And notes, genre, drive,
 * tech level, and the land never reach any of it: a story fact never moves a
 * number, and standing is an engine fact earned by resolved beats.
 */
class Standings
{
    /** Somebody was in a grip, and is loose and whole. */
    public const CAPTIVE_FREED = 'captive_freed';

    /** The thing this place could not handle went down. */
    public const ELITE_BEATEN = 'elite_beaten';

    /** A score closed without killing: bargained out, or taken and kept. */
    public const RIVAL_SPARED = 'rival_spared';

    /** Somebody broke and ran from here, and the tale wrote it down. */
    public const GRUDGE_BORN = 'grudge_born';

    /** The player put a piece of this place beyond use. */
    public const GROUND_WRECKED = 'ground_wrecked';

    /** They stayed toe-to-toe long enough that the district had to answer. */
    public const ALARM_ANSWERED = 'alarm_answered';

    /** The player swung first at somebody who had raised no hand — a person, not the wild's own. */
    public const INNOCENT_STRUCK = 'innocent_struck';

    /**
     * The closed table. Each event is worth exactly one point, and nothing
     * outside this list may move a standing — which is what keeps it readable
     * as "what you did here" rather than as a score with a formula.
     *
     * @var array<string,int>
     */
    public const EVENTS = [
        self::CAPTIVE_FREED => 1,
        self::ELITE_BEATEN => 1,
        self::RIVAL_SPARED => 1,
        self::GRUDGE_BORN => -1,
        self::GROUND_WRECKED => -1,
        self::ALARM_ANSWERED => -1,
        self::INNOCENT_STRUCK => -1,
    ];

    /**
     * The situation-board line, one tier to a line. Phrased as a fact about
     * how the people here deal with them and nothing else: no odds, no number,
     * and no word a land might not own.
     */
    private const BOARD = [
        'hostile' => 'Your name is spat here.',
        'wary' => 'This place is wary of you.',
        'known' => 'This place knows your name.',
        'welcome' => 'Doors open at your name here.',
    ];

    /** The one fact the narrator is handed, to be rendered in this land's own manners. */
    private const NARRATOR = [
        'hostile' => 'The people of this place remember them, and what they remember is bad. Doors shut, prices climb, and nobody offers anything unasked.',
        'wary' => 'The people of this place remember them and have not made up their minds. They are watched more closely than a stranger would be.',
        'known' => 'The people of this place know who they are and think well enough of them. A stranger would be dealt with more slowly.',
        'welcome' => 'The people of this place are glad they came back. Doors open at their name, and help is offered before it is asked for.',
    ];

    /**
     * What the turn says about the ground's opinion having moved. One plain
     * sentence in the same register as everything else the world does on its
     * own — never the tier, never a count, and absent entirely on the many
     * turns nothing here changed.
     */
    private const SHIFT = [
        1 => 'What they did here will be talked about, and talked about well.',
        -1 => 'What they did here will be talked about, and not kindly.',
    ];

    /**
     * Where this tale stands with the ground under this scene. A scene with no
     * campaign (the shared world) or no zone reads as nothing, and so does
     * every place the tale has never done anything in — which is most of them.
     */
    public static function of(?Scene $scene): int
    {
        if ($scene?->campaign_id === null || $scene->zone_id === null) {
            return 0;
        }

        $row = Standing::where('campaign_id', $scene->campaign_id)
            ->where('zone_id', $scene->zone_id)->first();

        return self::clamp((int) ($row?->score ?? 0));
    }

    /**
     * The tier, in the engine's own vocabulary. Five words for seven scores:
     * the two ends read alike, because the difference between a place that
     * dislikes you and one that loathes you is not something a door can show.
     */
    public static function tier(int $score): string
    {
        return match (true) {
            $score <= -2 => 'hostile',
            $score === -1 => 'wary',
            $score === 0 => 'silent',
            $score === 1 => 'known',
            default => 'welcome',
        };
    }

    /** The board line, or null at nothing — the empty group is then simply absent. */
    public static function line(int $score): ?string
    {
        return self::BOARD[self::tier($score)] ?? null;
    }

    /** The narrator's fixed fact, or null when this ground has nothing to say about them. */
    public static function fact(int $score): ?string
    {
        return self::NARRATOR[self::tier($score)] ?? null;
    }

    /**
     * The listener: a turn's detected events, applied to the ground it stood
     * on, clamped, and written into the append-only history.
     *
     * Every event appends even when the clamp swallows its point — the ledger
     * records what HAPPENED here, and a wrecked well that went unrecorded
     * because the score was already at the floor would leave the narrator
     * quoting a history that skipped the worst of it.
     *
     * @param  list<string>  $events  Keys of EVENTS, detected by the resolver
     *                                from facts it had already fixed.
     * @return string|null The plain shift fact, on the turns the score moved.
     */
    public static function record(Scene $scene, Turn $turn, array $events): ?string
    {
        // Anything the table does not know is not an event — and a word nobody
        // recognises must not so much as open a ledger for this ground.
        $events = array_values(array_filter($events, fn (string $event) => isset(self::EVENTS[$event])));

        if ($events === [] || $scene->campaign_id === null || $scene->zone_id === null) {
            return null;
        }

        $standing = Standing::firstOrCreate(
            ['campaign_id' => $scene->campaign_id, 'zone_id' => $scene->zone_id],
            ['score' => 0, 'history' => []],
        );

        $before = self::clamp((int) $standing->score);
        $score = $before;
        $history = $standing->history ?? [];

        foreach ($events as $event) {
            $shift = self::EVENTS[$event];
            $score = self::clamp($score + $shift);
            $history[] = ['turn_id' => $turn->id, 'event' => $event, 'shift' => $shift];
        }

        $standing->update(['score' => $score, 'history' => $history]);

        return $score === $before ? null : self::SHIFT[$score > $before ? 1 : -1];
    }

    /**
     * Is this the wrecking this scene counts?
     *
     * A player who takes four crates apart in one room has taken one room
     * apart. The cap is per SCENE rather than per turn, because the point is
     * the room, and it is charged the moment it is asked for — one call, one
     * answer, so there is no way to ask and then forget to spend it.
     */
    public static function chargeWreck(Scene $scene): bool
    {
        $cap = max(0, (int) config('game.standing.wrecks_per_scene', 1));
        $spent = (int) ($scene->state['standing_wrecked'] ?? 0);

        if ($spent >= $cap) {
            return false;
        }

        $scene->update(['state' => array_merge($scene->state ?? [], ['standing_wrecked' => $spent + 1])]);

        return true;
    }

    /**
     * The bias on a newly arrived enemy's FIRST telegraph.
     *
     * Standing never spawns, removes, or converts anybody, and never touches
     * what is hidden or lurking — it colors how company CARRIES ITSELF on
     * arrival, and nothing else. Hostile ground sends people in already
     * pressing; friendly ground sends them in hesitating, guarding and
     * circling while they work out whether this is a fight they want.
     *
     * It bends the roll the existing intent machinery already cast rather than
     * casting one of its own: same stream, same draw, same determinism — a
     * second roll here would silently move every seeded intent after it.
     */
    public static function bendFirstIntent(int $roll, int $score): int
    {
        return match (true) {
            $score < 0 => max(1, $roll - 2),
            $score > 0 => min(6, $roll + 2),
            default => $roll,
        };
    }

    /**
     * The narrator's block: how this ground holds them as the chapter closes,
     * plus the shift when the ground it stood on is the same ground.
     *
     * Plain facts about people and doors — no tier word, no number, nothing
     * the chapter could mistake for a stat. Empty on silent ground, so an
     * ordinary chapter carries no instructions about a place with no opinion.
     */
    public static function narratorBlock(Turn $turn): string
    {
        $scene = $turn->campaign?->activeScene ?? $turn->scene()->first();
        $fact = self::fact(self::of($scene));

        $moved = trim((string) ($turn->resolution['standing'] ?? ''));
        $moved = ($moved === '' || $scene?->zone_id !== $turn->scene()->first()?->zone_id)
            ? '' : " {$moved}";

        if ($fact === null && $moved === '') {
            return '';
        }

        $fact ??= 'This place had no opinion of them before today.';

        return "\n## How this place holds them (fixed fact)\n{$fact}{$moved}\n"
            ."Let it show ONCE, in how somebody here deals with them — a glance, a price, a door, a greeting — and let it ride inside the action. Never state it as a fact about their reputation, and never make it the chapter's subject.\n";
    }

    private static function clamp(int $score): int
    {
        $clamp = max(0, (int) config('game.standing.clamp', 3));

        return max(-$clamp, min($clamp, $score));
    }
}
