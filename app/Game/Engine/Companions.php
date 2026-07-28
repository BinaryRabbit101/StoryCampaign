<?php

namespace App\Game\Engine;

use App\Models\Actor;
use App\Models\Character;
use App\Models\Grudge;
use App\Models\Scene;
use App\Models\Turn;
use Illuminate\Support\Collection;

/**
 * What accumulates between you and whoever is walking beside you.
 *
 * Companions already existed and were already coordinated-never-controlled: a
 * request in the companion's own slot, rolled by the engine, and a failure that
 * can cost the companion rather than the player. What they did not have was
 * MEMORY. Every companion was the same companion on their thirtieth scene as on
 * their first, and losing one cost the tale a name and nothing else.
 *
 * So: a bond. One small integer on the companion's own actor row, moved only by
 * engine events the resolver has already rolled, and read in three places — the
 * board (plain words), the narrator (plain words), and two mechanical effects
 * that reuse vocabulary the engine already had.
 *
 * Four rules hold this up, and none of them may bend:
 *
 * 1. COORDINATED, NEVER CONTROLLED — at every tier. A deeper bond sharpens
 *    REQUESTS (the fellow's signature is one more card in their own slot) and
 *    adds THEIR OWN INITIATIVE (the sworn interception is engine-triggered,
 *    unbidden, and takes no player input at all). It never hands the player
 *    direct authorship of a companion's beat.
 * 2. BOND MOVEMENT IS FACT-DRIVEN. The listed engine events, and nothing else.
 *    A note, a chapter, a genre, a land — none of them move a bond by a point.
 *    Claude is told the tier in plain words and is never told the number.
 * 3. JOINING IS CONSENSUAL ON BOTH SIDES. Every path the world initiates ends
 *    in an engine-offered pair of ordinary main-slot cards, and sending someone
 *    on their way is never a dead choice.
 * 4. NO PARALLEL SYSTEMS. Bond effects are odds parts, Odds::CONDITIONS, and
 *    companion cards. There is no XP, no companion level, no second buff table.
 */
class Companions
{
    /** Newly met. They walk with you; nothing has happened yet. */
    public const STRANGER = 'stranger';

    /** They have bled beside you. A signature request opens here. */
    public const FELLOW = 'fellow';

    /** They would take the blow for you — and once a chapter, they do. */
    public const SWORN = 'sworn';

    /** How they came to be walking here. */
    public const ASKED = 'asked';

    public const GRATEFUL = 'grateful';

    public const STRAY = 'stray';

    /** The closed signature table. One is picked, once, when fellow is reached. */
    public const HARRY = 'companion_harry';

    public const DISTRACT = 'companion_distract';

    public const FORAGE = 'companion_forage';

    /** Statuses a companion never walks back from. The name is never respawned. */
    public const LOST_STATUSES = ['departed', 'dead'];

    /** Every verb a companion may be asked to attempt, signatures included. */
    public const REQUEST_VERBS = [
        'companion_block', 'companion_flank', 'companion_strike', 'companion_scout',
        self::HARRY, self::DISTRACT, self::FORAGE,
    ];

    /**
     * Which signature suits whom, keyed to what they ARE — the kind they were
     * before they fell in beside the player, and the tags they came with. A
     * creature drags an angle off you; a talker pulls an enemy's attention off
     * its own windup; a scout-ish soul walks the ground on your behalf.
     *
     * Several may match, and the seeded dice pick between them: the point is a
     * companion who feels like themselves, not a lookup with one right answer.
     */
    private const SIGNATURES = [
        self::HARRY => [
            'kinds' => ['creature', 'beast', 'animal', 'monster'],
            'tags' => ['feral', 'fierce', 'swift', 'restrainable'],
        ],
        self::DISTRACT => [
            'kinds' => ['npc', 'person', 'trader'],
            'tags' => ['talkable', 'persuadable', 'companionable', 'intimidatable'],
        ],
        self::FORAGE => [
            'kinds' => ['creature', 'npc', 'scout'],
            'tags' => ['scout', 'tracker', 'wary', 'forager'],
        ],
    ];

    /**
     * What gets talked about at a fire, as one plain sentence of fact for the
     * narrator to write a paragraph around.
     *
     * A closed list, seeded per companion, so the same soul does not open with
     * the same subject twice running. Two of the five reach for something the
     * tale has actually accumulated — an old wound, an unsettled score — because
     * a quiet page about nothing is the failure mode this whole beat exists to
     * avoid. Both fall back to the plain topics when the tale has nothing yet.
     *
     * (Keepsakes are deliberately absent. A memento is memory and nothing under
     * app/Game may so much as name the model — see App\Services\Mementos.)
     */
    private const CAMPFIRE_TOPICS = [
        'origin' => '{name} talked about where they came from, and what they left standing there.',
        'fear' => '{name} said, in fewer words than it deserved, what frightens them.',
        'road' => '{name} asked what came next, and took the answer seriously.',
        'scar' => '{name} asked about the {subject}, and what it costs to carry.',
        'grudge' => '{name} brought up {subject}, and said plainly what they thought of it.',
    ];

    /**
     * What a soul leaves behind when they are sent on their way. Colour, never
     * mechanics: send-away must not be a dead choice, and the cheapest honest
     * way to make it real is that the departing figure says something true on
     * the way out.
     */
    private const PARTING_GIFTS = [
        'They pointed out the way the ground ran ahead before they went, and were not wrong about it.',
        'They named who else walks this country, and what those ones want, and then they went.',
        'They said where they would be if it ever came to needing them, and meant it.',
        'They left the better part of what they were carrying, and would not be argued out of it.',
    ];

    // ---------------------------------------------------------------- reading

    public static function bond(Actor $companion): int
    {
        return max(0, (int) ($companion->tags['bond'] ?? 0));
    }

    /**
     * The tier, from one place and one place only.
     *
     * The stored word wins when it is there, because sworn is STICKY: once a
     * companion has stood in front of a blow for someone, a later dent cannot
     * un-happen it. Everything else derives from the number.
     */
    public static function tier(Actor $companion): string
    {
        $stored = $companion->tags['bond_tier'] ?? null;

        if ($stored === self::SWORN) {
            return self::SWORN;
        }

        return self::tierFor(self::bond($companion));
    }

    public static function tierFor(int $bond): string
    {
        $tiers = (array) config('game.companions.tiers', ['fellow' => 2, 'sworn' => 5]);

        return match (true) {
            $bond >= (int) ($tiers['sworn'] ?? 5) => self::SWORN,
            $bond >= (int) ($tiers['fellow'] ?? 2) => self::FELLOW,
            default => self::STRANGER,
        };
    }

    /** The board's plain words for a tier. Null at stranger — a new face needs no label. */
    public static function tierWord(string $tier): ?string
    {
        return match ($tier) {
            self::SWORN => 'sworn to you',
            self::FELLOW => 'they have bled beside you',
            default => null,
        };
    }

    /** Companions on their feet in this scene. @return Collection<int, Actor> */
    public static function present(Scene $scene): Collection
    {
        return $scene->actors()->where('kind', 'companion')->where('status', 'active')->orderBy('id')->get();
    }

    /** Everyone at the player's side, upright or otherwise. @return Collection<int, Actor> */
    public static function beside(Scene $scene): Collection
    {
        return $scene->actors()->where('kind', 'companion')
            ->whereIn('status', ['active', 'downed'])->orderBy('id')->get();
    }

    /**
     * Two is the cap, and a third candidate's offer simply does not fire. The
     * downed are counted: someone lying at your feet is still yours, and a party
     * that quietly refills while one of them is on the floor would be the game
     * telling the player their companion is already spent.
     */
    public static function atCap(Scene $scene): bool
    {
        return self::beside($scene)->count() >= (int) config('game.companions.cap', 2);
    }

    // ---------------------------------------------------------------- writing

    /**
     * Someone falls in beside them. Every path — asked, grateful, stray — ends
     * here, so a companion always starts at the same place with the same tags
     * and the tier ladder has exactly one entrance.
     *
     * The kind they WERE is kept: it is what the signature table is keyed to,
     * and flipping `kind` to companion would otherwise erase the only record
     * that this one used to be a dog.
     */
    public static function join(Actor $actor, string $via): Actor
    {
        $tags = $actor->tags ?? [];
        unset($tags['offering'], $tags['following'], $tags['stray_scenes'], $tags['witnessed']);

        $actor->update([
            'kind' => 'companion',
            'status' => 'active',
            'tags' => $tags + [
                'was_kind' => $actor->kind === 'companion' ? ($tags['was_kind'] ?? 'npc') : $actor->kind,
                'bond' => 0,
                'bond_tier' => self::STRANGER,
                'joined_via' => $via,
                'loyalty' => 1,
            ],
        ]);

        return $actor->fresh();
    }

    /**
     * Move a bond, and only ever from an engine event.
     *
     * `$key` is the reason, and a reason may only fire once per chapter for a
     * given companion — otherwise a turn where a request landed AND the block
     * it was part of turned a blow would pay twice for one act of loyalty, and
     * the ladder would climb at whatever rate the player could farm. A chapter
     * is one turn's telling, so the turn id IS the chapter stamp.
     *
     * Dents carry no key: getting them hurt costs trust every time it happens.
     */
    public static function nudge(Actor $companion, int $delta, ?Turn $turn = null, ?string $key = null): int
    {
        if ($companion->kind !== 'companion' || $delta === 0) {
            return self::bond($companion);
        }

        $tags = $companion->tags ?? [];
        $marks = (array) ($tags['bond_marks'] ?? []);

        if ($key !== null && $turn !== null) {
            if (($marks[$key] ?? null) === $turn->id) {
                return self::bond($companion);
            }
            $marks[$key] = $turn->id;
        }

        $max = (int) config('game.companions.bond_max', 6);
        $bond = max(0, min($max, self::bond($companion) + $delta));

        // Never below zero, and sworn is sticky: the history happened, and a
        // bad night cannot make it un-happen.
        $tier = self::tierFor($bond);
        if (($tags['bond_tier'] ?? null) === self::SWORN) {
            $tier = self::SWORN;
        }

        $tags['bond'] = $bond;
        $tags['bond_tier'] = $tier;
        $tags['bond_marks'] = $marks;
        $companion->update(['tags' => $tags]);

        return $bond;
    }

    /**
     * The signature, picked once and kept.
     *
     * Engine-picked with the turn's own seeded dice from the closed table above,
     * the moment the bond first reaches fellow. Nothing re-rolls it: a companion
     * whose one distinctive request changed between chapters would not read as a
     * person at all.
     */
    public static function ensureSignature(Actor $companion, Dice $dice): ?string
    {
        $tags = $companion->tags ?? [];

        if (isset($tags['signature'])) {
            return $tags['signature'];
        }

        if (self::tier($companion) === self::STRANGER) {
            return null;
        }

        $pool = self::signaturePool($companion);
        $pick = $pool[$dice->between(0, count($pool) - 1)];

        $tags['signature'] = $pick;
        $companion->update(['tags' => $tags]);

        return $pick;
    }

    /** The signature this companion carries, or null while none was earned. */
    public static function signature(Actor $companion): ?string
    {
        return self::tier($companion) === self::STRANGER
            ? null
            : ($companion->tags['signature'] ?? null);
    }

    /** @return list<string> */
    private static function signaturePool(Actor $companion): array
    {
        $nature = (string) ($companion->tags['was_kind'] ?? $companion->kind);
        $carried = array_keys(array_filter($companion->tags ?? [], fn ($v) => $v === true));

        $pool = [];
        foreach (self::SIGNATURES as $key => $rule) {
            if (in_array($nature, $rule['kinds'], true) || array_intersect($carried, $rule['tags']) !== []) {
                $pool[] = $key;
            }
        }

        // A companion the table has nothing to say about still gets one: the
        // fellow tier promises a signature, and a promise the engine sometimes
        // declines to keep is worse than a slightly generic pick.
        return $pool === [] ? array_keys(self::SIGNATURES) : $pool;
    }

    // ----------------------------------------------------------- interception

    /**
     * The fall that did not happen because of who was beside you.
     *
     * Unbidden and engine-triggered: no card, no slot, no player input. It fires
     * only when the blow being applied would actually put the character on the
     * floor, at most once per chapter per companion, and it fires BEFORE the
     * scar path can ever see a downed character — the damage is rerouted, so
     * there is no fall for Scars to roll against.
     *
     * The companion pays it in full, and may go down under it themselves.
     *
     * @return array{companion:string,actor_id:int,damage:int,downed:bool,fact:string}|null
     */
    public static function intercept(Character $character, Scene $scene, Turn $turn, int $damage): ?array
    {
        $health = (int) ($character->fresh()->meters['health']['current'] ?? 0);

        // Only the blow that would finish it. A scratch is theirs to take.
        if ($health <= 0 || $damage < $health) {
            return null;
        }

        $sworn = self::present($scene)->first(
            fn (Actor $a) => self::tier($a) === self::SWORN
                && ($a->tags['interception_turn_id'] ?? null) !== $turn->id,
        );

        if ($sworn === null) {
            return null;
        }

        $stats = $sworn->stats;
        $stats['health']['current'] = max(0, (int) ($stats['health']['current'] ?? 1) - $damage);

        $tags = $sworn->tags ?? [];
        $tags['interception_turn_id'] = $turn->id;

        $downed = $stats['health']['current'] === 0;
        if ($downed) {
            $tags['intercepted_fall'] = true;
        }

        $sworn->update([
            'stats' => $stats,
            'tags' => $tags,
            'status' => $downed ? 'downed' : $sworn->status,
        ]);

        $fact = $downed
            ? "{$sworn->name} got in the way of the blow that would have finished them, took all of it, and went down."
            : "{$sworn->name} got in the way of the blow that would have finished them and took it instead.";

        return [
            'companion' => $sworn->name,
            'actor_id' => $sworn->id,
            'damage' => $damage,
            'downed' => $downed,
            'fact' => $fact,
        ];
    }

    // --------------------------------------------------------------- campfire

    /**
     * A night at a fire, paid out.
     *
     * The bond point goes to the LOWEST-bond companion present — the one the
     * fire is actually for. The topic is seeded per companion off the turn, so
     * a re-resolved turn talks about the same thing, and it reaches for whatever
     * the tale has already accumulated before falling back to the plain three.
     *
     * @return array{companion:string,topic:string,fact:string,note:?string}|null
     */
    public static function campfire(Scene $scene, Turn $turn, ?string $note = null): ?array
    {
        $present = self::present($scene);
        if ($present->isEmpty()) {
            return null;
        }

        $companion = $present->sortBy(fn (Actor $a) => [self::bond($a), $a->id])->first();
        self::nudge($companion, 1, $turn, 'campfire');

        [$topic, $subject] = self::campfireTopic($companion, $scene, $turn);

        $fact = strtr(self::CAMPFIRE_TOPICS[$topic], [
            '{name}' => $companion->name,
            '{subject}' => (string) $subject,
        ]);

        return [
            'companion' => $companion->name,
            'topic' => $topic,
            'fact' => $fact,
            'note' => $note,
        ];
    }

    /** @return array{0:string,1:?string} */
    private static function campfireTopic(Actor $companion, Scene $scene, Turn $turn): array
    {
        $seed = crc32("campfire:{$turn->id}:{$companion->id}");

        // What the tale is actually carrying, if it is carrying anything.
        $character = $scene->campaign?->character;
        $marks = $character === null ? [] : Scars::marks($character);
        $score = $scene->campaign_id === null ? null : Grudge::where('campaign_id', $scene->campaign_id)
            ->whereIn('status', ['simmering', 'returning'])->orderByDesc('heat')->first();

        $options = ['origin', 'fear', 'road'];
        if ($marks !== []) {
            $options[] = 'scar';
        }
        if ($score !== null) {
            $options[] = 'grudge';
        }

        $topic = $options[$seed % count($options)];

        return [$topic, match ($topic) {
            'scar' => mb_strtolower((string) ($marks[count($marks) - 1]['label'] ?? 'old wound')),
            'grudge' => $score?->actor_name,
            default => null,
        }];
    }

    // ------------------------------------------------- companions the road provides

    /**
     * The stray: a soul the world put in the scene who takes an interest.
     *
     * Rolled off the actor's OWN id rather than off the scene's seeded stream,
     * deliberately — the dressing draw walks that stream, and adding a die per
     * spawn would shift every feature, actor and ambient every dressed scene has
     * ever produced. Same determinism, none of the collateral.
     *
     * Never an enemy, and never a name this tale has already lost.
     */
    public static function markStray(Actor $spawn, Scene $scene): bool
    {
        if ($spawn->kind === 'enemy' || $spawn->kind === 'companion') {
            return false;
        }

        if (self::atCap($scene) || self::alreadyLost($scene, $spawn->name)) {
            return false;
        }

        $chance = (float) config('game.companions.stray_chance', 0.08);
        if ($chance <= 0 || ! (new Dice($spawn->id * 2654435761 % PHP_INT_MAX))->chance($chance)) {
            return false;
        }

        $spawn->update(['tags' => array_merge($spawn->tags ?? [], [
            'following' => true,
            'stray_scenes' => 1,
            'witnessed' => false,
        ])]);

        return true;
    }

    /** Strays keeping to the edge of this scene. @return Collection<int, Actor> */
    public static function strays(Scene $scene): Collection
    {
        return $scene->visibleActors()->filter(
            fn (Actor $a) => ($a->tags['following'] ?? false) && $a->kind !== 'companion',
        )->values();
    }

    /**
     * A stray that was standing there when something of the player's landed has
     * seen what walking with them is like. It is the only thing that turns a
     * stray into a candidate — a soul who has watched you fail at everything has
     * no reason at all to ask.
     */
    public static function witness(Scene $scene, bool $succeeded): void
    {
        if (! $succeeded) {
            return;
        }

        foreach (self::strays($scene) as $stray) {
            if ($stray->tags['witnessed'] ?? false) {
                continue;
            }
            $stray->update(['tags' => array_merge($stray->tags, ['witnessed' => true])]);
        }
    }

    /** Strays walk transitions the way companions do: they follow, or they are not following. */
    public static function walkStrays(Scene $from, Scene $to): void
    {
        foreach (self::strays($from) as $stray) {
            $stray->update([
                'scene_id' => $to->id,
                'tags' => array_merge($stray->tags, [
                    'stray_scenes' => (int) ($stray->tags['stray_scenes'] ?? 1) + 1,
                ]),
            ]);
        }
    }

    /**
     * The stray finally asks. Two conditions, both the world's: it has watched
     * something of the player's actually work, and it has walked far enough to
     * mean it. A stray nobody ever engages simply stays a stray — ambience, not
     * obligation.
     */
    public static function maybeOfferStray(Scene $scene): ?Actor
    {
        if (self::atCap($scene) || self::offerStanding($scene) !== null) {
            return null;
        }

        $floor = (int) config('game.companions.stray_scenes', 2);

        $ready = self::strays($scene)->first(
            fn (Actor $a) => ($a->tags['witnessed'] ?? false)
                && (int) ($a->tags['stray_scenes'] ?? 0) >= $floor,
        );

        if ($ready === null) {
            return null;
        }

        $ready->update(['tags' => array_merge($ready->tags, ['offering' => self::STRAY])]);

        return $ready->fresh();
    }

    /**
     * The grateful: someone the player got out of something, asking to stay.
     *
     * Only ever off a genuine rescue the engine can see in its own facts — a
     * captive who was bound when the turn began and is loose and whole at the
     * end of it — and only if they are not hostile. Seeded, once per chapter,
     * and silent while the party is full: nobody is ever saddled with a third.
     *
     * @param  list<int>  $rescued  Actor ids freed by this turn's facts.
     */
    public static function maybeOfferGrateful(Scene $scene, Dice $dice, array $rescued): ?Actor
    {
        if ($rescued === [] || self::atCap($scene) || self::offerStanding($scene) !== null) {
            return null;
        }

        $candidate = $scene->actors()->whereIn('id', $rescued)->where('status', 'active')
            ->where('kind', '!=', 'enemy')->where('kind', '!=', 'companion')
            ->orderBy('id')->get()
            ->reject(fn (Actor $a) => self::alreadyLost($scene, $a->name))
            ->first();

        if ($candidate === null) {
            return null;
        }

        if (! $dice->chance((float) config('game.companions.grateful_chance', 0.4))) {
            return null;
        }

        $candidate->update(['tags' => array_merge($candidate->tags ?? [], ['offering' => self::GRATEFUL])]);

        return $candidate->fresh();
    }

    /** The one soul currently waiting on an answer, if any. */
    public static function offerStanding(Scene $scene): ?Actor
    {
        return $scene->visibleActors()->first(fn (Actor $a) => isset($a->tags['offering']));
    }

    /** A parting that is not a dead choice: they go, and they leave something true behind. */
    public static function partingGift(Actor $actor): string
    {
        return self::PARTING_GIFTS[crc32("parting:{$actor->id}") % count(self::PARTING_GIFTS)];
    }

    // ------------------------------------------------------------------- loss

    /**
     * Scene exit decides it.
     *
     * A companion who went down does not vanish mid-scene — they lie there,
     * breathing, on the board, for as long as the scene lasts. What happens next
     * is rolled against the bond and nothing else: a stranger takes stock of
     * what walking with this person costs and slips away; a fellow or a sworn
     * gets up and walks on at one health. The single exception is the sworn who
     * went down taking a blow meant for the player in a fight the player did not
     * win — that one, rarely, is final.
     *
     * @return array{facts:list<string>,lost:list<string>}
     */
    public static function resolveDowned(Scene $scene, Dice $dice, bool $fightLost): array
    {
        $facts = [];
        $lost = [];

        $down = $scene->actors()->where('kind', 'companion')->where('status', 'downed')->orderBy('id')->get();

        foreach ($down as $companion) {
            $tier = self::tier($companion);

            if ($tier === self::SWORN && ($companion->tags['intercepted_fall'] ?? false) && $fightLost
                && $dice->chance((float) config('game.companions.sworn_final_chance', 0.25))) {
                $companion->update(['status' => 'dead']);
                $facts[] = "{$companion->name} never came round from the blow they took in their place.";
                $lost[] = $companion->name;

                continue;
            }

            if ($tier === self::STRANGER) {
                $companion->update(['status' => 'departed']);
                $facts[] = "{$companion->name} came round, weighed what walking with them had already cost, and slipped away without saying so.";
                $lost[] = $companion->name;

                continue;
            }

            $stats = $companion->stats;
            $stats['health']['current'] = 1;
            $tags = $companion->tags ?? [];
            unset($tags['intercepted_fall']);

            $companion->update(['stats' => $stats, 'tags' => $tags, 'status' => 'active']);
            $facts[] = "{$companion->name} got up when the scene turned — hurt, slow, and still walking beside them.";
        }

        return ['facts' => $facts, 'lost' => $lost];
    }

    /** A name this tale has already lost never walks back into it. */
    private static function alreadyLost(Scene $scene, string $name): bool
    {
        if ($scene->campaign_id === null) {
            return false;
        }

        return Actor::where('name', $name)
            ->whereIn('status', self::LOST_STATUSES)
            ->whereIn('scene_id', Scene::where('campaign_id', $scene->campaign_id)->select('id'))
            ->exists();
    }

    // ---------------------------------------------------------------- reading out

    /**
     * The allies group, in plain words. The tier is said, the number never is —
     * a board that printed "bond 4" would teach the player to farm it.
     *
     * @return list<string>
     */
    public static function boardLines(Scene $scene): array
    {
        $lines = [];

        foreach (self::beside($scene) as $companion) {
            $tell = $companion->status === 'downed'
                ? 'down, breathing'
                : self::tierWord(self::tier($companion));

            $lines[] = $companion->name.($tell === null ? '' : " — {$tell}");
        }

        return $lines;
    }

    /**
     * The strays and the askers, for the bystanders group. A stray is scenery
     * with an opinion; a soul waiting on an answer has to be unmissable, because
     * the cards for it are standing in the list this turn and not the next.
     *
     * @return list<string>
     */
    public static function bystanderLines(Scene $scene): array
    {
        $lines = [];

        foreach ($scene->visibleActors() as $actor) {
            if (in_array($actor->kind, ['companion', 'enemy'], true)) {
                continue;
            }
            if (isset($actor->tags['offering'])) {
                $lines[] = $actor->name.' — asking to walk with you';
            } elseif ($actor->tags['following'] ?? false) {
                $lines[] = $actor->name.' — keeping near you';
            }
        }

        return $lines;
    }

    /**
     * Everything the narrator is told about who is walking beside them: the
     * tiers in plain words, the fire, the interception, and the loss. No number
     * reaches this, and no mechanics language does either.
     *
     * Empty string when the tale is walking alone and nothing happened, so an
     * ordinary chapter carries no instructions about companions it does not have.
     */
    public static function narratorBlock(Turn $turn): string
    {
        $scene = $turn->scene()->first() ?? $turn->campaign?->activeScene;
        $events = $turn->resolution['companions'] ?? [];

        $lines = [];

        if ($scene !== null) {
            foreach (self::beside($scene) as $companion) {
                $lines[] = "- {$companion->name} walks with them, and ".self::narratorTierWord(self::tier($companion))
                    .($companion->status === 'downed' ? ' They are down and breathing, not dead.' : '');
            }

            foreach (self::strays($scene) as $stray) {
                $lines[] = "- {$stray->name} keeps near them without being asked to, at the edge of things. Nothing has been said about it either way.";
            }
        }

        $moments = [];

        $campfire = $events['campfire'] ?? null;
        if ($campfire !== null) {
            $note = ($campfire['note'] ?? null) === null ? ''
                : ' The player\'s own words for that fire — voice and colour only, they change nothing that happened: "'
                    .str_replace('"', "'", $campfire['note']).'"';
            $moments[] = "- Before this vignette they shared a fire with {$campfire['companion']}. {$campfire['fact']}{$note}"
                ."\n  Write it as ONE quiet paragraph near the open — people talking, in their own voices, and nothing decided. Not a speech, not a montage, and never the chapter's subject.";
        }

        $interception = $events['interception'] ?? null;
        if ($interception !== null) {
            $moments[] = "- {$interception['fact']} Nobody asked them to and there was no time to. Write it as the thing it is: the fall that did not happen because of who was standing there.";
        }

        foreach ($events['joined'] ?? [] as $fact) {
            $moments[] = "- {$fact}";
        }
        foreach ($events['parted'] ?? [] as $fact) {
            $moments[] = "- {$fact}";
        }
        foreach ($events['loss'] ?? [] as $fact) {
            $moments[] = "- {$fact}";
        }

        if ($lines === [] && $moments === []) {
            return '';
        }

        $who = $lines === [] ? '' : "Who is beside them:\n".implode("\n", $lines)."\n";
        $what = $moments === [] ? '' : "What passed between them this time:\n".implode("\n", $moments)."\n";

        return "\n## The people walking with them (fixed facts — say none of this as a status, write it as behaviour)\n"
            .$who.$what;
    }

    private static function narratorTierWord(string $tier): string
    {
        return match ($tier) {
            self::SWORN => 'has already bled for them once and would again — write them as someone who no longer asks whether to.',
            self::FELLOW => 'has fought at their shoulder often enough to be trusted with the dangerous half of a plan.',
            default => 'is still half a stranger to them, walking along for reasons of their own.',
        };
    }
}
