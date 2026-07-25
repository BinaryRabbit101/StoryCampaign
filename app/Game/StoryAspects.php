<?php

namespace App\Game;

/**
 * The story axes a player sets at creation: the GENRE the world wears, what
 * DRIVES the tale, and how much magic or machinery the world runs on.
 *
 * Every one of these is narration only. They color the forge, the interview,
 * the chapters, and the evolver — and they touch NOTHING the engine decides.
 * Cards, difficulty, dice, meters, stat clamps, and the affordance grammar are
 * identical in a space-horror campaign and a medieval one; a westerner's climb
 * and a starfarer's climb roll the same. Flexible story, fixed rules.
 *
 * Each axis takes either a catalog key (the player picked from the list) or
 * whatever they typed. Typed text is passed to Claude verbatim — the catalog
 * is a menu, never a fence. Only GENRE has an engine consequence, and a soft
 * one: it narrows which lands `WorldFlavor` may roll, so a starfaring tale
 * cannot open on chalk downs. A typed genre is matched against the catalog's
 * aliases; anything still unrecognized simply leaves the land pool open.
 */
class StoryAspects
{
    /** Free-typed values are the player's own words — kept, not parsed, but bounded. */
    public const MAX_LENGTH = 120;

    /**
     * The genres. `lands` is derived from WorldFlavor's own tags, so the two
     * catalogs cannot drift apart.
     *
     * @return array<string, array{label: string, brief: string, aliases: list<string>}>
     */
    public static function genres(): array
    {
        return [
            'grounded-fantasy' => [
                'label' => 'Grounded low fantasy',
                'brief' => 'Grounded low fantasy: strange gifts and stranger creatures, but no world-breaking magic. The uncanny is rare, local, and never explained away.',
                'aliases' => ['low fantasy', 'fantasy', 'folklore', 'folk'],
            ],
            'high-fantasy' => [
                'label' => 'High fantasy',
                'brief' => 'High fantasy: an older, larger world where the impossible is part of the landscape — floating stone, sleeping giants, powers with names and opinions.',
                'aliases' => ['epic fantasy', 'mythic', 'myth', 'sword and sorcery'],
            ],
            'medieval' => [
                'label' => 'Medieval, no magic',
                'brief' => 'Historical medieval: walls, seasons, tithes, and politics. Nothing supernatural is real, though plenty of people believe otherwise and act on it.',
                'aliases' => ['historical', 'middle ages', 'medieval history', 'feudal'],
            ],
            'western' => [
                'label' => 'Western / frontier',
                'brief' => 'Western: frontier country where law is a person rather than an institution, distances are punishing, and everyone arrived recently for a reason.',
                'aliases' => ['wild west', 'frontier', 'cowboy', 'old west'],
            ],
            'pirates' => [
                'label' => 'Pirates / age of sail',
                'brief' => 'Age of sail piracy: shares argued in public, ports that ask no questions, and the constant arithmetic of wind, water, and who is behind you.',
                'aliases' => ['pirate', 'sailing', 'buccaneer', 'high seas', 'nautical'],
            ],
            'space' => [
                'label' => 'Space / science fiction',
                'brief' => 'Science fiction: stations, colonies, and long distances, where air and power are things somebody controls. Technology is ordinary; the isolation is not.',
                'aliases' => ['sci-fi', 'scifi', 'science fiction', 'aliens', 'starship', 'space opera', 'futuristic'],
            ],
            'cyberpunk' => [
                'label' => 'Cyberpunk / neon noir',
                'brief' => 'Cyberpunk: a dense, owned city where technology is intimate and cheap, everything is somebody\'s property, and the rain does not stop.',
                'aliases' => ['cyber', 'neon', 'noir', 'dystopia', 'corporate'],
            ],
            'horror' => [
                'label' => 'Horror',
                'brief' => 'Horror: the world is wrong in a specific way and answers slowly. Dread is built from what is withheld, and the ordinary keeps being ordinary right up until it is not.',
                'aliases' => ['scary', 'gothic', 'haunted', 'supernatural horror', 'creepy'],
            ],
            'post-apocalypse' => [
                'label' => 'After the collapse',
                'brief' => 'Post-collapse: the large systems are gone and the small ones are improvised. Everything useful is inherited, repaired, or fought over.',
                'aliases' => ['apocalypse', 'apocalyptic', 'wasteland', 'collapse', 'survival horror'],
            ],
            'anime' => [
                'label' => 'Anime / heightened',
                'brief' => 'Anime-flavored: heightened, expressive, and unembarrassed — declared intentions, rivalries that matter, spirits and gifts treated as ordinary facts of life.',
                'aliases' => ['manga', 'shonen', 'shounen', 'jrpg'],
            ],
            'modern-realistic' => [
                'label' => 'Modern & realistic',
                'brief' => 'Present day, no powers: phones, paperwork, weather, and consequences that behave exactly as they do in life. Tension comes from people and circumstance.',
                'aliases' => ['modern', 'realistic', 'contemporary', 'real world', 'present day'],
            ],
        ];
    }

    /**
     * What the tale is shaped like. Pure narration: the engine still resolves
     * whatever cards the scene offers, whatever the drive says.
     *
     * @return array<string, array{label: string, brief: string, aliases: list<string>}>
     */
    public static function drives(): array
    {
        return [
            'rescue' => ['label' => 'A rescue', 'aliases' => ['save someone', 'rescue mission'],
                'brief' => 'A rescue: someone is held, lost, or running out of time, and the tale bends toward reaching them.'],
            'escape' => ['label' => 'An escape', 'aliases' => ['breakout', 'get out', 'flee'],
                'brief' => 'An escape: the way out is the whole problem, and every scene is measured by whether it got closer.'],
            'survival' => ['label' => 'Survival', 'aliases' => ['endure', 'stay alive'],
                'brief' => 'Survival: shelter, water, warmth, and the next day. Success is measured in what was kept, not what was won.'],
            'discovery' => ['label' => 'Discovery', 'aliases' => ['explore', 'exploration', 'uncover'],
                'brief' => 'Discovery: the world is the goal. What lies past the next ridge matters more than who is standing on it.'],
            'mystery' => ['label' => 'A mystery', 'aliases' => ['investigation', 'whodunnit', 'solve'],
                'brief' => 'A mystery: something happened, the account of it is wrong, and the truth is held by people with reasons.'],
            'revenge' => ['label' => 'Revenge', 'aliases' => ['vengeance', 'payback'],
                'brief' => 'Revenge: an old debt with a name on it, and the slow question of what collecting it costs.'],
            'heist' => ['label' => 'A heist', 'aliases' => ['theft', 'steal', 'robbery', 'caper'],
                'brief' => 'A heist: something is held somewhere well defended, and the tale is the taking of it.'],
            'protect' => ['label' => 'Protect something', 'aliases' => ['defend', 'guard', 'hold the line'],
                'brief' => 'A protection: a person, a place, or a thing must survive, and the pressure on it only grows.'],
            'pilgrimage' => ['label' => 'A journey', 'aliases' => ['pilgrimage', 'travel', 'road trip', 'quest'],
                'brief' => 'A journey: somewhere far must be reached, and the road does the shaping.'],
            'rebellion' => ['label' => 'Rebellion', 'aliases' => ['uprising', 'revolution', 'resistance'],
                'brief' => 'A rebellion: something powerful holds this place, and the tale is the slow work of unseating it.'],
            'homecoming' => ['label' => 'A homecoming', 'aliases' => ['return', 'go home'],
                'brief' => 'A homecoming: a return to somewhere that has changed, or that remembers you differently than you remember it.'],
        ];
    }

    /**
     * How much magic or machinery the world runs on. Narration only — it may
     * never grant the character a capability. Powers come from the character
     * sheet and items, both engine-priced.
     *
     * @return array<string, array{label: string, brief: string, aliases: list<string>}>
     */
    public static function techLevels(): array
    {
        return [
            'mundane' => ['label' => 'Realistic — nothing uncanny', 'aliases' => ['realistic', 'none', 'no magic', 'grounded'],
                'brief' => 'Nothing supernatural is real. Every effect in the world has an ordinary cause, whatever the locals say about it.'],
            'low-magic' => ['label' => 'Low magic — rare and quiet', 'aliases' => ['some magic', 'subtle magic', 'rare magic'],
                'brief' => 'Magic is real, rare, and unexplained. It arrives as luck, omen, or something in the water — never as a system anyone controls.'],
            'high-magic' => ['label' => 'High magic — worked openly', 'aliases' => ['magic', 'lots of magic', 'wizards', 'spellcasting'],
                'brief' => 'Magic is worked openly and traded like any other craft. It shapes buildings, work, and war, and everyone knows someone who does it.'],
            'spirits' => ['label' => 'Spirits & the unseen', 'aliases' => ['ghosts', 'spirit', 'haunted', 'psychic', 'the occult'],
                'brief' => 'The unseen is populated: spirits, the dead, and things with old agreements. They are dealt with by custom, not by force.'],
            'clockwork' => ['label' => 'Steam & clockwork', 'aliases' => ['steampunk', 'industrial', 'victorian', 'gaslight'],
                'brief' => 'Industrial-age machinery: steam, gears, and pressure. Impressive, heavy, and always one bad weld from failing.'],
            'near-future' => ['label' => 'Near-future tech', 'aliases' => ['modern tech', 'cybernetics', 'implants', 'drones'],
                'brief' => 'Technology a few decades ahead: networks, prosthetics, drones. Cheap where it is common, and it fails like anything mass-produced.'],
            'starfaring' => ['label' => 'Far-future / starfaring', 'aliases' => ['space tech', 'ftl', 'advanced', 'far future'],
                'brief' => 'Starfaring technology, old and worn in: ships, habitats, and life support that people maintain rather than marvel at.'],
            'alien' => ['label' => 'Alien contact', 'aliases' => ['aliens', 'first contact', 'xeno'],
                'brief' => 'Something genuinely not human shares this world, with its own logic. It is not a monster and not a person, and it is not going away.'],
        ];
    }

    /**
     * Resolve a submitted value to a catalog key: an exact key, or a typed
     * phrase whose words match a catalog label or alias. Null means the player
     * typed something the catalog does not know — which is allowed, and stays
     * their words.
     *
     * @param  array<string, array{label: string, brief: string, aliases: list<string>}>  $catalog
     */
    public static function resolve(array $catalog, ?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        if (isset($catalog[$value])) {
            return $value;
        }

        foreach ($catalog as $key => $entry) {
            $needles = array_merge([$entry['label'], str_replace('-', ' ', $key)], $entry['aliases']);
            foreach ($needles as $needle) {
                if (str_contains($value, mb_strtolower($needle))) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * One prompt line for an axis. A catalog pick becomes its authored brief;
     * anything else is handed to Claude in the player's own words.
     *
     * @param  array<string, array{label: string, brief: string, aliases: list<string>}>  $catalog
     */
    public static function brief(array $catalog, ?string $value, string $prefix): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $key = self::resolve($catalog, $value);
        $text = $key !== null && $key === $value
            ? $catalog[$key]['brief']
            // Typed words stay the player's. A near-match lends its brief for
            // texture, but never overwrites what they actually asked for.
            : $value.($key !== null ? ' — '.$catalog[$key]['brief'] : '');

        return "{$prefix} {$text}";
    }

    /**
     * The pickable options for one axis, for the creation form.
     *
     * @param  array<string, array{label: string, brief: string, aliases: list<string>}>  $catalog
     * @return list<array{key: string, label: string, brief: string}>
     */
    public static function options(array $catalog): array
    {
        return collect($catalog)
            ->map(fn (array $entry, string $key) => [
                'key' => $key,
                'label' => $entry['label'],
                'brief' => $entry['brief'],
            ])
            ->values()->all();
    }
}
