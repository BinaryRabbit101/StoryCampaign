<?php

namespace App\Services;

use App\Game\Engine\Dice;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\EchoLine;
use App\Models\Memento;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The shelf of finished books, speaking to the tale being written.
 *
 * The player's campaigns already shared a history and never knew it: the land
 * roll refuses their last three lands, the compiled books pile up per user, and
 * not one of them could reference another. Every new tale began in total
 * amnesia. This is the single line that crosses — when a moment HERE rhymes
 * with a moment a closed book already preserved, that preserved line surfaces
 * as a memory.
 *
 * Five rules hold the whole thing up.
 *
 * 1. QUOTATION, never invention. Every row stores the source campaign, whether
 *    the words came off the shelf or out of a chapter, and which row exactly —
 *    so the quote is re-derivable from the source at any time, and the clamp
 *    below checks the rewording against it rather than against a copy. A player
 *    with no finished tales gets silence, forever; Claude is never asked to
 *    remember anything on their behalf.
 * 2. Only CLOSED books. Sources are the same user's ended campaigns and nothing
 *    else. A sibling tale still being played never leaks — its moments are not
 *    memories yet — and another player's tales are not this player's life.
 * 3. It QUOTES and never instantiates. A companion's name may be spoken; no
 *    actor, zone, item, grudge, or feature crosses campaigns through here.
 *    Items already have their own sanctioned door and this is not a second one.
 * 4. COLOUR, and nothing else. An echo is never a card, an odds part, a board
 *    group, or a resolver path; it reveals nothing and moves no number. Like the
 *    shelf and the queue, the rule is enforced by direction too: nothing under
 *    app/Game may name the model, so the engine only ever detects the RHYME from
 *    facts it already fixed and hands the pick out here.
 * 5. RARE, capped, and FOUND. A seeded chance per qualifying rhyme, a ceiling on
 *    the tale, a cooldown between them, one per turn, and each source line
 *    speaks at most once in any one campaign. No push ever fires for one.
 */
class Echoes
{
    /** A mark taken where another life took a mark. */
    public const THE_MARK = 'the_mark';

    /** A score closed where another life closed one. */
    public const THE_RIVAL = 'the_rival';

    /** Somebody sworn to them, or somebody lost — as it went before. */
    public const THE_COMPANY = 'the_company';

    /** Ground that another tale of theirs was set in. */
    public const OLD_GROUND = 'old_ground';

    /** An ending gathering, as one gathered before. */
    public const THE_GATHERING = 'the_gathering';

    /**
     * The closed rhyme list, and this order IS the priority rule when a turn
     * qualifies for several at once. Rarest first, the shelf's own convention:
     * a mark and a settled score are the moments a tale is remembered for, and
     * old ground is the one that could fire at every border.
     *
     * @var list<string>
     */
    public const RHYMES = [
        self::THE_MARK,
        self::THE_RIVAL,
        self::THE_COMPANY,
        self::OLD_GROUND,
        self::THE_GATHERING,
    ];

    /** The quoted words came off a finished tale's shelf. */
    public const MEMENTO = 'memento';

    /** The quoted words are a chapter's own epigraph. */
    public const CHAPTER = 'chapter';

    /**
     * How many words of WRAPPER Claude may spend around the quote. The quoted
     * line itself is not counted — it is fixed, and a long keepsake line must
     * not shrink the frame that carries it.
     *
     * Thirty rather than the shelf's twenty because the wrapper is carrying two
     * jobs the shelf's line never had: saying that this is a memory, and naming
     * the tale it belongs to. A budget that the ENGINE's own frame plus a long
     * book title would already breach is not a clamp, it is a refusal.
     */
    public const MAX_FRAME_WORDS = 30;

    /**
     * The engine's frame per rhyme, in two registers — because whether the
     * shelf speaks as MEMORY or as LEGEND is a claim about the world, and the
     * engine only makes the claim it can back.
     *
     * When the remembered tale stood on a DIFFERENT land, the frame is a
     * memory: a thing that comes to them out of another life, owing nobody any
     * geography. When the two tales share their land, the universe demonstrably
     * is one place — so the frame speaks as the land's own story, and the
     * player's old protagonists get their afterlife as figures the ground
     * still tells of. Same rhymes, same caps, same clamps; only the voice
     * changes, and only where it is earned.
     *
     * @var array<string, string>
     */
    private const MEMORY_FRAMES = [
        self::THE_MARK => 'Another life of theirs came away marked like this. "{quote}" — from the tale of {title}.',
        self::THE_RIVAL => 'Another life of theirs closed a score like this one. "{quote}" — from the tale of {title}.',
        self::THE_COMPANY => 'Somebody walked beside them once before, in another life. "{quote}" — from the tale of {title}.',
        self::OLD_GROUND => 'They have stood on ground like this before. "{quote}" — from the tale of {title}.',
        self::THE_GATHERING => 'Another life of theirs gathered toward its end like this. "{quote}" — from the tale of {title}.',
    ];

    /** @var array<string, string> */
    private const LEGEND_FRAMES = [
        self::THE_MARK => 'This land still tells of one who came away marked like this. "{quote}" — from the tale of {title}.',
        self::THE_RIVAL => 'They tell a story here of a score closed like this one. "{quote}" — from the tale of {title}.',
        self::THE_COMPANY => 'This land remembers two who walked it together once. "{quote}" — from the tale of {title}.',
        self::OLD_GROUND => 'This ground remembers one who stood here before them. "{quote}" — from the tale of {title}.',
        self::THE_GATHERING => 'They still tell how an older tale gathered toward its end here. "{quote}" — from the tale of {title}.',
    ];

    /** Which shelf each memento-column rhyme draws from, and only that one. */
    private const COLUMNS = [
        self::THE_MARK => 'scar_taken',
        self::THE_RIVAL => 'rival_settled',
        self::THE_COMPANY => 'companion_lost',
    ];

    /** No mechanics language reaches a chapter, from any author. */
    private const MECHANICS_PATTERN = '/\b(dice|die|dc|rolls?|cards?|meters?|difficulty|modifiers?|hit points|health)\b/iu';

    /**
     * Surface at most one memory for this turn.
     *
     * The resolver hands in the rhymes it read off facts it had already fixed;
     * everything else — whether there is anything to remember at all, which
     * closed book it comes out of, and whether the tale is allowed one right
     * now — is decided here, outside the engine.
     *
     * @param  list<string>  $rhymes  Everything this turn rhymed with.
     * @param  Dice  $dice  The turn's own seeded stream: a re-resolved turn
     *                      remembers exactly what it remembered the first time.
     * @return array{rhyme:string, line:string}|null
     */
    public static function consider(Turn $turn, array $rhymes, Dice $dice): ?array
    {
        $campaign = $turn->campaign_id === null ? null : $turn->campaign;

        if ($campaign === null || $rhymes === []) {
            return null;
        }

        if (! self::allowedNow($campaign, $turn)) {
            return null;
        }

        $tales = self::closedBooks($campaign);
        if ($tales->isEmpty()) {
            // The first tale of a life has nothing behind it. That is silence,
            // and silence is the whole answer — there is no fallback that is
            // not an invention.
            return null;
        }

        $used = EchoLine::where('campaign_id', $campaign->id)
            ->get()->map(fn (EchoLine $e) => "{$e->source_type}:{$e->source_id}")->all();

        foreach (self::RHYMES as $rhyme) {
            if (! in_array($rhyme, $rhymes, true)) {
                continue;
            }

            $candidates = array_values(array_filter(
                self::candidates($rhyme, $campaign, $tales),
                fn (array $c) => ! in_array("{$c['type']}:{$c['id']}", $used, true),
            ));

            if ($candidates === []) {
                // A rhyme whose column is empty (or already spent) is silent.
                // It never borrows from another column: a settled score
                // answered by somebody else's keepsake is not a rhyme.
                continue;
            }

            // The chance is rolled only once something could actually be
            // remembered, so a tale with nothing behind it never burns it.
            if (! $dice->chance((float) config('game.echoes.chance', 0.25))) {
                return null;
            }

            return self::write($campaign, $turn, $rhyme, $candidates[$dice->between(0, count($candidates) - 1)]);
        }

        return null;
    }

    /**
     * The caps, all four of them. Nothing here reads the world — a memory is
     * refused for being too frequent, never for being inconvenient.
     */
    private static function allowedNow(Campaign $campaign, Turn $turn): bool
    {
        $cap = (int) config('game.echoes.campaign_cap', 4);
        if ($cap <= 0 || EchoLine::where('campaign_id', $campaign->id)->count() >= $cap) {
            return false;
        }

        // One per turn, and a chapter is one turn's telling.
        if (EchoLine::where('turn_id', $turn->id)->exists()) {
            return false;
        }

        $cooldown = (int) config('game.echoes.cooldown_chapters', 3);
        if ($cooldown <= 0) {
            return true;
        }

        $last = EchoLine::where('campaign_id', $campaign->id)->orderByDesc('id')->first();
        if ($last === null) {
            return true;
        }

        // Counted in turns for the same reason the shelf counts its keepsakes
        // that way: one turn is one chapter, and the turn number is a fact that
        // exists whether or not the narration run ever managed to write it.
        $since = Turn::whereKey($last->turn_id)->value('number');

        return $since === null || ($turn->number - (int) $since) >= $cooldown;
    }

    /**
     * The books that are allowed to speak: this player's own, ended, and not
     * this one. A tale still being played is not a memory, and another
     * player's tales are not this player's life.
     */
    private static function closedBooks(Campaign $campaign): Collection
    {
        return Campaign::where('user_id', $campaign->user_id)
            ->whereKeyNot($campaign->id)
            ->where('status', 'completed')
            ->orderBy('id')->get();
    }

    /**
     * Everything this rhyme could draw on, in its OWN column and no other.
     *
     * @param  Collection<int, Campaign>  $tales
     * @return list<array{type:string, id:int, campaign:Campaign, quote:string}>
     */
    private static function candidates(string $rhyme, Campaign $campaign, Collection $tales): array
    {
        if (isset(self::COLUMNS[$rhyme])) {
            return Memento::whereIn('campaign_id', $tales->modelKeys())
                ->where('trigger', self::COLUMNS[$rhyme])
                ->orderBy('id')->get()
                ->map(fn (Memento $m) => self::candidate(
                    self::MEMENTO, $m->id, $tales->firstWhere('id', $m->campaign_id), $m->line,
                ))
                ->filter()->values()->all();
        }

        if ($rhyme === self::OLD_GROUND) {
            // The land is fixed for a campaign's life, so this is a match
            // between two whole tales rather than between two rooms. Read the
            // stored flavor directly: asking the campaign for one would ROLL a
            // land for a tale that has not got one, which is a write, and
            // remembering must never change anything.
            $land = $campaign->world_flavor;
            if ($land === null || $land === '') {
                return [];
            }

            return $tales->where('world_flavor', $land)
                ->map(function (Campaign $tale) {
                    // What they picked up the first time they stood on it, and
                    // failing that, how that tale opened.
                    $souvenir = Memento::where('campaign_id', $tale->id)
                        ->where('trigger', 'first_ground')->orderBy('id')->first();

                    if ($souvenir !== null) {
                        return self::candidate(self::MEMENTO, $souvenir->id, $tale, $souvenir->line);
                    }

                    $opening = self::chapterLine($tale, closing: false);

                    return $opening === null ? null
                        : self::candidate(self::CHAPTER, $opening->id, $tale, (string) $opening->intent_line);
                })
                ->filter()->values()->all();
        }

        if ($rhyme === self::THE_GATHERING) {
            return $tales->map(function (Campaign $tale) {
                $closing = self::chapterLine($tale, closing: true);

                return $closing === null ? null
                    : self::candidate(self::CHAPTER, $closing->id, $tale, (string) $closing->intent_line);
            })->filter()->values()->all();
        }

        return [];
    }

    /**
     * A tale's opening or closing epigraph — the one line per chapter that was
     * always written to stand on its own. Chapters without one (a coda, a
     * chronicle) are passed over rather than quoted from the body: an echo is a
     * LINE, and half a paragraph is not one.
     */
    private static function chapterLine(Campaign $tale, bool $closing): ?Chapter
    {
        return Chapter::where('campaign_id', $tale->id)
            ->whereNotNull('intent_line')->where('intent_line', '!=', '')
            ->orderBy('number', $closing ? 'desc' : 'asc')
            ->first();
    }

    /** @return array{type:string, id:int, campaign:Campaign, quote:string}|null */
    private static function candidate(string $type, int $id, ?Campaign $tale, ?string $quote): ?array
    {
        $quote = trim((string) $quote);

        return $tale === null || $quote === '' ? null
            : ['type' => $type, 'id' => $id, 'campaign' => $tale, 'quote' => $quote];
    }

    /**
     * Write the memory down, frame and all.
     *
     * Persisted the instant the turn resolves, exactly as a keepsake is: an
     * echo that waited for narration would not exist on the evenings Claude is
     * down, which are precisely the evenings it is the only thing the chapter
     * would have had.
     *
     * @param  array{type:string, id:int, campaign:Campaign, quote:string}  $pick
     * @return array{rhyme:string, line:string}
     */
    private static function write(Campaign $campaign, Turn $turn, string $rhyme, array $pick): array
    {
        $line = self::assemble(
            $rhyme, $pick['quote'], self::titleOf($pick['campaign']),
            legend: self::sharedGround($campaign, $pick['campaign']),
        );

        EchoLine::create([
            'campaign_id' => $campaign->id,
            'source_campaign_id' => $pick['campaign']->id,
            'source_type' => $pick['type'],
            'source_id' => $pick['id'],
            'rhyme' => $rhyme,
            'line' => $line,
            'turn_id' => $turn->id,
            // Stamped when the chapter telling it exists (see reword), the same
            // rule the shelf's citation lives by.
            'chapter_id' => null,
        ]);

        return ['rhyme' => $rhyme, 'line' => $line];
    }

    private static function assemble(string $rhyme, string $quote, string $title, bool $legend): string
    {
        $frames = $legend ? self::LEGEND_FRAMES : self::MEMORY_FRAMES;

        return trim(strtr($frames[$rhyme] ?? $frames[self::OLD_GROUND], [
            '{quote}' => $quote,
            '{title}' => $title,
        ]));
    }

    /**
     * Whether the two tales stood on the same land — the whole test for the
     * legend register. Derived rather than stored, like the quote: the land is
     * fixed for a campaign's life, so the answer cannot drift, and reading the
     * stored flavor directly never rolls one for a tale that has none.
     */
    private static function sharedGround(Campaign $campaign, Campaign $tale): bool
    {
        $land = trim((string) $campaign->world_flavor);

        return $land !== '' && $land === trim((string) $tale->world_flavor);
    }

    /** What that tale is called on the shelf: its bound title, else its name. */
    private static function titleOf(Campaign $tale): string
    {
        $title = trim((string) $tale->title);

        return $title === '' ? $tale->name : $title;
    }

    /**
     * The quoted words, re-read from the source they came out of.
     *
     * Deliberately derived rather than stored: the clamp below checks the
     * rewording against the REAL memento or chapter, so a row that no longer
     * traces back to something the player lived cannot be reworded at all.
     */
    public static function quoteOf(EchoLine $echo): ?string
    {
        $quote = match ($echo->source_type) {
            self::MEMENTO => Memento::whereKey($echo->source_id)->value('line'),
            self::CHAPTER => Chapter::whereKey($echo->source_id)->value('intent_line'),
            default => null,
        };

        $quote = trim((string) $quote);

        return $quote === '' ? null : $quote;
    }

    /**
     * The narrator's invitation. One block, and only on a chapter that actually
     * remembered something — an ordinary chapter carries no instructions about
     * another book. Returns '' otherwise.
     */
    public static function narratorBlock(Turn $turn): string
    {
        $echo = self::forTurn($turn);
        $quote = $echo === null ? null : self::quoteOf($echo);

        if ($echo === null || $quote === null) {
            return '';
        }

        $words = self::MAX_FRAME_WORDS;
        $source = $echo->sourceCampaign;
        $title = self::titleOf($source ?? $turn->campaign);
        $line = $echo->line;

        // The register is re-derived, never trusted from the stored line: the
        // land is fixed for both tales' lives, so the same echo always speaks
        // in the same voice, retries included.
        if ($source !== null && self::sharedGround($turn->campaign, $source)) {
            return <<<REMEMBER

            ## A story this land still tells (fixed fact — the quoted words are exact)
            {$line}
            This is a LEGEND of this same land — an older, finished tale that truly happened on this ground, and the character may have heard it told. Give it one small moment inside the action — a story recalled, a name spoken by the fire — and move on. It is telling, not happening: nobody from that tale is present, nobody in this scene acts on it, and it changes nothing standing here.
            You may reword the wrapper around the quoted words in at most {$words} words, if it lands better told in this land's voice. The quoted words themselves, and the name "{$title}", must appear exactly as given. Otherwise repeat the whole line exactly.

            REMEMBER;
        }

        return <<<REMEMBER

        ## Something they remember from another life (fixed fact — the quoted words are exact)
        {$line}
        This is a MEMORY of a tale that is already finished, not something happening here. Give it one small moment inside the action — a thing that comes to them and is let go of — and move on. Nobody in this scene knows it, nobody acts on it, and it changes nothing standing here.
        That other tale was set in a different land: name it only as the memory it is, and never bring its ground, its weather, or its people into this one. Nobody and nothing from it is present.
        You may reword the wrapper around the quoted words in at most {$words} words, if it lands better in this land's voice. The quoted words themselves, and the name "{$title}", must appear exactly as given. Otherwise repeat the whole line exactly.

        REMEMBER;
    }

    /**
     * Claude's proposal, clamped — and the chapter stamp that completes the
     * echo's provenance.
     *
     * The frame is all that is ever writable, and only when the proposal is
     * short enough, still names the tale it came from, still carries the quoted
     * line VERBATIM, and speaks no mechanics. Any violation, or no proposal at
     * all, and the engine's words stand. The quote is checked against the
     * source row itself, so no rewording can quietly edit a memory.
     */
    public static function reword(Turn $turn, ?Chapter $chapter, mixed $proposed): void
    {
        $echo = self::forTurn($turn);

        if ($echo === null) {
            return;
        }

        $update = [];

        if ($chapter !== null && $echo->chapter_id === null) {
            $update['chapter_id'] = $chapter->id;
        }

        $line = is_string($proposed) ? trim($proposed) : '';
        $quote = self::quoteOf($echo);
        $title = self::titleOf($echo->sourceCampaign ?? $turn->campaign);

        if ($line !== '' && $quote !== null
            && str_contains($line, $quote)
            && str_contains(mb_strtolower($line), mb_strtolower($title))
            && self::frameWithin($line, $quote, self::MAX_FRAME_WORDS)
            && preg_match(self::MECHANICS_PATTERN, str_replace($quote, '', $line)) !== 1) {
            $update['line'] = $line;
        }

        if ($update !== []) {
            $echo->update($update);
        }
    }

    /** The memory this turn surfaced, if it surfaced one. */
    public static function forTurn(Turn $turn): ?EchoLine
    {
        return EchoLine::where('turn_id', $turn->id)->orderBy('id')->first();
    }

    /** How many times this tale has heard from the shelf. */
    public static function count(Campaign $campaign): int
    {
        return EchoLine::where('campaign_id', $campaign->id)->count();
    }

    /**
     * The wrapper alone, measured. The quote is subtracted first — it is fixed,
     * and a long remembered line must never eat the budget of the sentence
     * carrying it.
     */
    private static function frameWithin(string $line, string $quote, int $max): bool
    {
        $frame = trim(str_replace($quote, ' ', $line));

        if ($frame === '') {
            return false;
        }

        return count(preg_split('/\s+/', $frame)) <= $max;
    }

    /**
     * Whether a user has anything behind them at all. Read by nothing
     * mechanical — it exists so a caller can tell "no finished tales" from
     * "nothing rhymed", which are the same silence and very different bugs.
     */
    public static function hasClosedBooks(User $user): bool
    {
        return Campaign::where('user_id', $user->id)->where('status', 'completed')->exists();
    }
}
