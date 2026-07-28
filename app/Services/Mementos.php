<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Memento;
use App\Models\Turn;

/**
 * The trophy shelf.
 *
 * Notable resolved moments leave an object behind: the thing in their hand
 * when they went down, a token off a rival whose score finally closed,
 * something small picked up off new country. The shelf fills slowly, compiles
 * into the finished book as a closing appendix ("What you carried home"), and
 * costs the balance sheet nothing at all.
 *
 * Two rules hold this whole feature up.
 *
 * 1. It is INERT. A memento grants nothing, is never an item, never reaches a
 *    card, an odds part, or a resolver path. That is why it may be minted by
 *    play itself while items still enter only through evolution — the rule
 *    items obey exists because items give the character power, and this gives
 *    the character nothing but memory. The inertness is enforced by type and
 *    by direction: this service lives outside app/Game, and nothing under
 *    app/Game imports the model. The engine detects the MOMENT (from facts a
 *    resolution has already fixed) and hands it out here.
 *
 * 2. The ENGINE mints it. The trigger list below is closed, the words are
 *    engine-templated, and the row is written the instant the turn resolves —
 *    so the keepsake exists even when Claude is unavailable and the narration
 *    never happens. Claude is invited, later and inside a clamp, to give the
 *    same object better words. That is the only thing it may change.
 */
class Mementos
{
    /**
     * The closed trigger list, RAREST FIRST — this order IS the priority rule
     * when several fire in one chapter.
     *
     * One entry has no engine source yet and is deliberately listed anyway:
     * `endeavor_filled` waits on clocks. Arriving means adding the key here and
     * one detection block in the resolver — a pure addition, with nothing
     * downstream to change. That is exactly how `companion_lost` arrived.
     *
     * `companion_lost` sits second because losing somebody who was walking with
     * you is rarer than anything below it and costs more than all of it: the
     * shelf is where that stops being a status on an actor row.
     */
    public const TRIGGERS = [
        'rival_settled',
        'companion_lost',
        'scar_taken',
        'endeavor_filled',
        'elite_beaten',
        'captive_freed',
        'first_ground',
    ];

    /** The clamp on anything Claude proposes back. */
    public const MAX_NAME_WORDS = 8;

    public const MAX_LINE_WORDS = 20;

    /**
     * Engine-written words, per trigger, one seeded pick per mint. Deliberately
     * plain and setting-neutral: the shelf has to read honestly on an ash
     * steppe, aboard a derelict station, and in a boomtown alike, and the
     * narrator is the one who knows which. No mechanics language ever.
     *
     * @var array<string, list<array{name:string, line:string}>>
     */
    private const TEMPLATES = [
        'rival_settled' => [
            ['name' => 'What {subject} left behind', 'line' => 'Kept from {place}, the day the score with {subject} closed for good.'],
            ['name' => "{subject}'s token", 'line' => 'Taken off {subject} at {place}, with nothing further left to settle.'],
            ['name' => 'The last of {subject}', 'line' => 'Carried out of {place} once {subject} was finished with.'],
        ],
        'companion_lost' => [
            ['name' => 'What {subject} carried', 'line' => 'It was {subject}\'s, and after {place} there was nobody left to give it back to.'],
            ['name' => "{subject}'s share", 'line' => 'Kept from {place}, where {subject} stopped walking with them.'],
            ['name' => 'The last of {subject}', 'line' => 'All that came away from {place} of {subject}, who did not.'],
        ],
        'scar_taken' => [
            ['name' => 'What was in their hand', 'line' => 'They were holding it when {subject} put them down at {place}.'],
            ['name' => 'The mark of {place}', 'line' => 'It came away with them from {place}, where {subject} left its mark.'],
            ['name' => 'A piece of {subject}', 'line' => 'Broken off {subject} at {place}, on the way to the floor.'],
        ],
        'endeavor_filled' => [
            ['name' => 'The end of {subject}', 'line' => 'Kept from {place}, the hour {subject} was seen all the way through.'],
            ['name' => '{subject}, finished', 'line' => 'A small thing off {place}, from the day {subject} was done at last.'],
        ],
        'elite_beaten' => [
            ['name' => "{subject}'s mark", 'line' => 'Off {subject}, who did not get up again at {place}.'],
            ['name' => 'Taken from {subject}', 'line' => 'Carried away from {place}, where {subject} was finally brought down.'],
            ['name' => 'What {subject} carried', 'line' => 'It was {subject}\'s until {place}, and then it was theirs.'],
        ],
        'captive_freed' => [
            ['name' => 'A token from {subject}', 'line' => 'Pressed on them at {place} by {subject}, who had nothing else to give.'],
            ['name' => "{subject}'s thanks", 'line' => 'Given at {place} by {subject}, walking free and in a hurry.'],
            ['name' => 'What {subject} left them', 'line' => 'Left in their hand at {place} by {subject}, on the way out.'],
        ],
        'first_ground' => [
            ['name' => 'First ground of {subject}', 'line' => 'Picked up at {place}, the first of {subject} they ever walked.'],
            ['name' => 'Something off {subject}', 'line' => 'Taken from {place}, on the day {subject} was still new country.'],
            ['name' => 'A piece of {subject}', 'line' => 'Small, and from {place} — the first of {subject} they stood on.'],
        ],
    ];

    /** Words too ordinary to prove a rewording is still about the same subject. */
    private const HOLLOW_WORDS = ['the', 'a', 'an', 'of', 'and', 'that', 'this', 'their', 'them', 'with', 'from', 'some'];

    /**
     * Mechanics language in a proposed wording means the clamp keeps the
     * engine's words. Whole words only: a derelict STATION is not a stat, and
     * a keepsake that got refused for containing one would be the guard
     * costing the shelf its best lines.
     */
    private const MECHANICS_PATTERN = '/\b(dice|die|dc|rolls?|cards?|meters?|difficulty|modifiers?|hit points|health)\b/iu';

    /**
     * Mint at most one keepsake for this turn.
     *
     * Called from the resolver the moment a turn's facts are final, and stored
     * immediately: a memento that waited for narration would not exist on the
     * evenings Claude is down, which are exactly the evenings the shelf is the
     * only thing left of the chapter.
     *
     * @param  list<array{trigger:string, subject:string, place:string}>  $candidates
     *                                                                                 Everything this turn qualified for; the rarest one wins.
     */
    public static function mint(Turn $turn, array $candidates): ?Memento
    {
        if ($turn->campaign_id === null || $candidates === []) {
            return null;
        }

        // Sparse by design. A shelf of forty trinkets is an inventory; a shelf
        // of nine is a life. One per chapter (a chapter is one turn's telling,
        // so the per-turn count IS the per-chapter cap), and a ceiling on the
        // tale as a whole.
        $perChapter = (int) config('game.mementos.per_chapter', 1);
        $perCampaign = (int) config('game.mementos.per_campaign', 12);

        if (Memento::where('turn_id', $turn->id)->count() >= $perChapter) {
            return null;
        }
        if (Memento::where('campaign_id', $turn->campaign_id)->count() >= $perCampaign) {
            return null;
        }

        $candidate = self::rarest($turn->campaign_id, $candidates);
        if ($candidate === null) {
            return null;
        }

        $words = self::templatedWords($turn, $candidate);

        return Memento::create([
            'campaign_id' => $turn->campaign_id,
            'turn_id' => $turn->id,
            // Stamped when the chapter telling it exists (see reword). A
            // memento minted before its own chapter cannot cite it yet, and
            // citing the PREVIOUS chapter would point the book at the wrong page.
            'chapter_id' => null,
            'trigger' => $candidate['trigger'],
            'subject' => $candidate['subject'],
            'name' => $words['name'],
            'line' => $words['line'],
        ]);
    }

    /**
     * The priority rule: when several triggers fire in one chapter, mint the
     * rarest. Unknown keys are ignored rather than trusted — the list is closed.
     *
     * @param  list<array{trigger:string, subject:string, place:string}>  $candidates
     * @return array{trigger:string, subject:string, place:string}|null
     */
    private static function rarest(int $campaignId, array $candidates): ?array
    {
        foreach (self::TRIGGERS as $trigger) {
            foreach ($candidates as $candidate) {
                if (($candidate['trigger'] ?? null) !== $trigger) {
                    continue;
                }

                // New country is a once-per-zone souvenir: crossing back over
                // old ground is not the first time you ever saw it.
                if ($trigger === 'first_ground' && Memento::where('campaign_id', $campaignId)
                    ->where('trigger', 'first_ground')
                    ->where('subject', $candidate['subject'])->exists()) {
                    continue;
                }

                return $candidate;
            }
        }

        return null;
    }

    /**
     * The engine's own words for this keepsake: a seeded pick from the
     * trigger's small pattern list, carrying the name of whoever or whatever
     * the moment was about. Seeded on the turn, so a re-resolved turn writes
     * the same words it wrote the first time.
     *
     * @param  array{trigger:string, subject:string, place:string}  $candidate
     * @return array{name:string, line:string}
     */
    private static function templatedWords(Turn $turn, array $candidate): array
    {
        $patterns = self::TEMPLATES[$candidate['trigger']] ?? self::TEMPLATES['first_ground'];
        $pick = $patterns[crc32("memento:{$turn->id}:{$candidate['trigger']}") % count($patterns)];

        $replace = [
            '{subject}' => $candidate['subject'],
            '{place}' => $candidate['place'],
        ];

        return [
            'name' => trim(strtr($pick['name'], $replace)),
            'line' => trim(strtr($pick['line'], $replace)),
        ];
    }

    /**
     * The narrator's invitation. One line, and only when this chapter actually
     * minted something — an ordinary chapter carries no instructions about a
     * shelf. Returns '' otherwise.
     */
    public static function narratorBlock(Turn $turn): string
    {
        $memento = self::forTurn($turn);
        if ($memento === null) {
            return '';
        }

        $name = self::MAX_NAME_WORDS;
        $line = self::MAX_LINE_WORDS;

        return <<<KEEP

        ## The keepsake this moment leaves behind (fixed fact — the object exists; only its words are yours)
        "{$memento->name}" — {$memento->line}
        If the chapter you just wrote gives this object better words, give them: a name of at most {$name} words and one plain sentence of at most {$line}. Both must still be about {$memento->subject}, and the object itself, what it is and where it came from, is fixed. Otherwise repeat these exactly.

        KEEP;
    }

    /**
     * Claude's proposal, clamped — and the chapter stamp that completes the
     * memento's provenance.
     *
     * The row already exists and already reads well; this can only replace its
     * two words-fields, and only when the proposal is short enough, still about
     * the same subject, and free of mechanics language. Any violation, or no
     * proposal at all, leaves the engine's words standing. Nothing else about
     * the memento is ever writable.
     */
    public static function reword(Turn $turn, ?Chapter $chapter, mixed $proposed): void
    {
        $memento = self::forTurn($turn);
        if ($memento === null) {
            return;
        }

        $update = [];

        if ($chapter !== null && $memento->chapter_id === null) {
            $update['chapter_id'] = $chapter->id;
        }

        $name = is_array($proposed) ? trim((string) ($proposed['name'] ?? '')) : '';
        $line = is_array($proposed) ? trim((string) ($proposed['line'] ?? '')) : '';

        if (self::withinWords($name, self::MAX_NAME_WORDS)
            && self::withinWords($line, self::MAX_LINE_WORDS)
            && self::mentionsSubject("{$name} {$line}", $memento->subject)
            && ! self::speaksMechanics("{$name} {$line}")) {
            $update['name'] = $name;
            $update['line'] = $line;
        }

        if ($update !== []) {
            $memento->update($update);
        }
    }

    /** The keepsake this turn minted, if it minted one. */
    public static function forTurn(Turn $turn): ?Memento
    {
        return Memento::where('turn_id', $turn->id)->orderBy('id')->first();
    }

    /**
     * The shelf, in chapter order — what the campaign page shows and what the
     * book's closing section is compiled from. An empty shelf is an empty
     * array, and every reader of it draws nothing at all rather than an empty
     * heading.
     *
     * @return list<array{name:string, line:string, chapter:?int}>
     */
    public static function shelf(Campaign $campaign): array
    {
        $chapters = Chapter::where('campaign_id', $campaign->id)->pluck('number', 'id');

        return Memento::where('campaign_id', $campaign->id)->orderBy('id')->get()
            ->map(fn (Memento $m) => [
                'name' => $m->name,
                'line' => $m->line,
                // Null while the chapter telling it has not been written (or
                // never was). The keepsake still stands on the shelf; it just
                // has no page to point at yet.
                'chapter' => $chapters[$m->chapter_id] ?? null,
            ])
            ->sortBy(fn (array $entry) => $entry['chapter'] ?? PHP_INT_MAX)
            ->values()->all();
    }

    /** The newest keepsake's name — one line of flavor for the widget. */
    public static function latestName(Campaign $campaign): ?string
    {
        return Memento::where('campaign_id', $campaign->id)->orderByDesc('id')->value('name');
    }

    /**
     * The shelf as plain lines, for the coda prompt. Empty string when nothing
     * was carried home, so a closing page is never handed an empty list.
     */
    public static function promptList(Campaign $campaign): string
    {
        return collect(self::shelf($campaign))
            ->map(fn (array $m) => "- {$m['name']}: {$m['line']}")
            ->join("\n");
    }

    private static function withinWords(string $text, int $max): bool
    {
        if ($text === '') {
            return false;
        }

        return count(preg_split('/\s+/', $text)) <= $max;
    }

    /**
     * Still about the same thing. A rewording that quietly swaps the subject is
     * a different keepsake wearing this one's row, so it is refused: some
     * substantial word of the subject has to survive into the new wording.
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

    /** No mechanics language reaches the shelf or the book, from any author. */
    private static function speaksMechanics(string $text): bool
    {
        return preg_match(self::MECHANICS_PATTERN, $text) === 1;
    }
}
