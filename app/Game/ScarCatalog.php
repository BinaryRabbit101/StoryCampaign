<?php

namespace App\Game;

use App\Game\Engine\Dice;

/**
 * The scar table: what a fall leaves behind.
 *
 * A scar is a `TraitCatalog` burden acquired mid-tale instead of at creation.
 * It uses the same machinery — a row in the character's constraints, priced
 * into cards and odds through the one ledger — and differs in exactly two
 * ways: the ENGINE picks it (seeded dice, from this closed table, keyed to how
 * the character went down), and it refunds NOTHING. Creation burdens buy
 * points; a scar buys nothing. It is the price of falling, not a currency.
 *
 * Six entries, deliberately: enough that two falls never read as the same
 * injury twice, few enough that each one is a distinct thing the reader
 * remembers. Each takes a different part of the body and therefore a different
 * corner of the verb vocabulary — a leg, a side, an eye, the hands, the
 * throat, the nerve — so a character carrying two scars is hampered in two
 * genuinely different ways rather than twice in the same one.
 *
 * Nothing here reads the campaign's genre, land, drive, or tech level. Which
 * scar lands and what it costs are fixed across every tale; only the narration
 * of it moves. The MECHANICAL half lives in Odds::SCARS — one ladder, two
 * readers, so a card's forecast and the die it is measured against can never
 * disagree about what the old wound costs.
 */
class ScarCatalog
{
    /** The constraint `source` that marks a burden as taken in play, not bought. */
    public const SOURCE = 'scar';

    /**
     * The closed table. The key IS the constraint name, so the pricing table in
     * Odds and the row on the sheet cannot drift apart by a rename.
     *
     * `fact` is the narrator's plain-words version — a fixed fact about a body,
     * carrying no mechanics and no numbers, that later chapters may name.
     *
     * @var array<string, array{label:string, description:string, fact:string, params:array}>
     */
    private const CATALOG = [
        'marked_limp' => [
            'label' => 'A marked limp',
            'description' => 'The knee took the worst of it and never came all the way back. Climbing, crossing, and running for it all cost more than they used to.',
            'fact' => 'Their knee will not fully bend again, and they walk with a limp they are not going to lose.',
            'params' => ['part' => 'a knee'],
        ],
        'guarded_side' => [
            'label' => 'A guarded side',
            'description' => 'Something in the ribs healed wrong. Wrestling, hauling, and anything that asks the whole body to close on someone comes harder.',
            'fact' => 'One side of them never healed straight, and they guard it without meaning to.',
            'params' => ['part' => 'the ribs'],
        ],
        'dimmed_eye' => [
            'label' => 'A dimmed eye',
            'description' => 'One eye came back cloudy. Reading ground, catching what is hunting you, and following a trail are all half the work they were.',
            'fact' => 'One of their eyes has gone cloudy and reads the world at half strength.',
            'params' => ['part' => 'an eye'],
        ],
        'unsteady_hands' => [
            'label' => 'A tremor in the hands',
            'description' => 'The hands shake now, faintly and always. Lifting, forcing, and going back for what was dropped all want a grip that is no longer certain.',
            'fact' => 'Their hands shake now — faintly, constantly, and it does not stop when they want it to.',
            'params' => ['part' => 'the hands'],
        ],
        'ruined_voice' => [
            'label' => 'A ruined voice',
            'description' => 'The throat was crushed and the voice came back wrong. Every word they spend on someone lands with less behind it.',
            'fact' => 'Their voice came back from it wrong — quieter, rougher, and it costs them to use.',
            'params' => ['part' => 'the throat'],
        ],
        'lingering_flinch' => [
            'label' => 'A flinch that will not leave',
            'description' => 'Some part of them saw the floor coming and never unlearned it. Committing to violence takes a beat longer than it did.',
            'fact' => 'There is a flinch in them now, at the moment of committing, that was not there before.',
            'params' => ['trigger' => 'the moment of committing'],
        ],
    ];

    /**
     * How the character went down → the two or three scars that fall could
     * plausibly leave. Keyed to what the resolver already knows at the end of a
     * turn: which side of the beat finished them, and how.
     *
     * Every context offers a real choice of injuries, so the same death twice
     * does not produce the same body twice.
     *
     * @var array<string, list<string>>
     */
    private const CONTEXTS = [
        // A plain blow, taken standing.
        'struck_down' => ['guarded_side', 'lingering_flinch', 'marked_limp'],
        // The heavy telegraphed blow, landed.
        'crushed' => ['guarded_side', 'unsteady_hands', 'marked_limp'],
        // Something they never saw.
        'ambushed' => ['dimmed_eye', 'lingering_flinch', 'guarded_side'],
        // Their own body failed them on the ground itself.
        'fall' => ['marked_limp', 'unsteady_hands', 'dimmed_eye'],
        // Went down at close hold, in among them.
        'overwhelmed' => ['ruined_voice', 'guarded_side', 'unsteady_hands'],
    ];

    /** @return array<string, array> */
    public static function all(): array
    {
        return self::CATALOG;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    /** @return list<string> */
    public static function contexts(): array
    {
        return array_keys(self::CONTEXTS);
    }

    /** One scar, said the way the sheet and the book will say it. */
    public static function get(?string $key): ?array
    {
        $entry = self::CATALOG[$key] ?? null;

        return $entry === null ? null : ['key' => $key] + $entry;
    }

    /** Whether a constraint name is one this table minted. */
    public static function isScar(?string $name): bool
    {
        return $name !== null && isset(self::CATALOG[$name]);
    }

    /**
     * Roll the scar this fall left, from the scene's own seeded dice.
     *
     * Never the same injury twice: what the body already carries is struck from
     * the candidates first, and a context whose whole shortlist is already
     * carried falls back to the rest of the table rather than repeating itself.
     *
     * @param  list<string>  $carried  Scar keys already on this sheet.
     */
    public static function roll(string $context, array $carried, Dice $dice): ?array
    {
        $candidates = array_values(array_diff(self::CONTEXTS[$context] ?? self::keys(), $carried));

        if ($candidates === []) {
            $candidates = array_values(array_diff(self::keys(), $carried));
        }

        if ($candidates === []) {
            return null;
        }

        return self::get($candidates[$dice->between(0, count($candidates) - 1)]);
    }
}
