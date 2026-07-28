<?php

namespace App\Services\Claude;

use App\Game\Capability;
use App\Game\Meters;
use App\Game\TraitCatalog;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\InterviewMessage;
use App\Models\Turn;
use App\Notifications\InterviewReplyNotification;
use App\Notifications\StoryBegunNotification;
use App\Services\CapabilityClamp;
use App\Services\TurnStarter;
use Illuminate\Support\Facades\DB;

/**
 * The narrative character creation/growth interview. Claude speaks in-world
 * and translates the player's description into a structured loadout; the
 * CapabilityClamp keeps every magnitude inside the design bible's bounds.
 * For growth, Claude may push back in-world when a request is out of scope.
 */
class Interviewer
{
    /**
     * Past this, a narrator reply is assumed to have outlasted the player's
     * attention and earns a push. Below it, the answer is already on screen
     * by the time a notification could arrive.
     */
    private const SLOW_REPLY_SECONDS = 15;

    public function __construct(
        private readonly ClaudeCli $claude,
        private readonly CapabilityClamp $clamp,
        private readonly TurnStarter $starter,
        private readonly StageBuilder $stage,
        private readonly ZoneForge $forge,
    ) {}

    public function open(Campaign $campaign): InterviewMessage
    {
        return InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'creation',
            'role' => 'narrator',
            'body' => 'Before the world takes shape around you, tell me who steps into it. '
                .'Describe yourself — form, temperament, the things you can do that others cannot, '
                .'and the price those gifts carry. Speak freely; I will listen.',
            // A hand for players who freeze on the blank page: tappable
            // starting points, each a full answer they can send or reshape.
            'suggestions' => [
                'A huge black cat with a prehensile tail — strong enough to carry a person, too big to go unnoticed.',
                'A slight, quick shadow of a person who can squeeze through anywhere, but folds fast in a straight fight.',
                'A patient old giant: slow, hard to move, able to lift what three others could not.',
                'A silver-tongued wanderer who can talk their way past most trouble — and is helpless when talk fails.',
            ],
        ]);
    }

    /**
     * Begin a campaign with a hero from an earlier tale. The sheet carries
     * over exactly — capabilities (growth included), constraints, items,
     * meter maxima — with pools refilled; Claude writes a returning
     * prologue instead of running the creation interview. No new power
     * enters the world through this door.
     */
    public function returnCharacter(Campaign $campaign, Character $original): void
    {
        // Claude runs before the transaction: a slow CLI call must never
        // sit inside a database lock, and a failed one falls back to stock
        // prose rather than failing the campaign. The world is forged first
        // so the prologue's stage plan can reference the forged ground.
        $this->forge->ensureStartingZone($campaign);
        $opening = $this->stage->plan($campaign, $original->description);

        $turn = DB::transaction(function () use ($campaign, $original, $opening) {
            $meters = $original->meters;
            $meters['health']['current'] = $meters['health']['max'];
            foreach ($meters['tempo'] ?? [] as $name => $pool) {
                $meters['tempo'][$name]['current'] = $pool['max'];
            }

            $character = Character::create([
                'campaign_id' => $campaign->id,
                'name' => $original->name,
                'description' => $original->description,
                'attack_styles' => $original->attack_styles,
                'meters' => $meters,
                'status' => 'alive',
                'meters_regenerated_at' => now(),
            ]);

            foreach ($original->capabilities as $capability) {
                $character->capabilities()->create($capability->only(['capability', 'magnitude', 'grade', 'scope', 'source']));
            }

            foreach ($original->constraints as $constraint) {
                $character->constraints()->create($constraint->only(['name', 'params', 'coupled_capability', 'source']));
            }

            foreach ($original->items as $item) {
                $character->items()->attach($item->id, [
                    'equipped' => (bool) $item->pivot->equipped,
                    'charges' => $item->pivot->charges,
                ]);
            }

            $campaign->update(['status' => 'active', 'started_at' => now()]);

            return $this->starter->openFirstTurn($campaign, $opening);
        });

        // The prologue is written LAST, against the scene that now exists.
        $this->recordPrologue($campaign, $this->returningPrologue($campaign, $original, $turn));

        $this->announceBegun($campaign);
    }

    private function returningPrologue(Campaign $campaign, Character $original, Turn $turn): string
    {
        $previous = $original->campaign;
        $lastChapter = $previous?->chapters()->reorder('number', 'desc')->first();
        $closing = $lastChapter === null ? '(their earlier tale is unrecorded)' : mb_substr($lastChapter->plainBody(), -800);
        $land = $campaign->worldBrief();
        $stage = $campaign->stageBrief();
        $stageSection = $stage === '' ? '' : "\n## The player set the stage for this new tale\n{$stage}\n";
        $register = ProseStyle::rules();
        $landing = $this->landing($turn);

        try {
            return $this->claude->prompt(<<<PROMPT
A hero returns for a new tale in a living-world RPG. Write a 200-400 word prologue in third-person past tense: {$original->name} steps out of an earlier story and into this new one, "{$campaign->name}". Carry the weight of where their last tale left off, but open cleanly — a new book, not a recap. No mechanics language.

{$register}

## The land this new tale is set in (fixed — they arrive HERE, and it is not where they came from)
{$land}
{$stageSection}

## The character
{$original->name}: {$original->description}

## Where their last tale ("{$previous?->name}") left them
{$closing}

{$landing}

Respond with ONLY the prologue prose.
PROMPT);
        } catch (\Throwable $e) {
            report($e);

            return "{$original->name} stepped once more into the waiting world. What was learned in the last tale came along; what was lost stayed lost. Somewhere ahead, a new story was already making room.";
        }
    }

    /**
     * Where the prologue has to arrive.
     *
     * The prologue used to be written before the opening scene existed, so it
     * ended wherever the prose felt like ending and the first chapter then
     * began somewhere else entirely — two stories stapled together, with the
     * reader asked to step over the gap. It is written last now, and this is
     * the ground it has to land on: the exact place, the exact cast, the exact
     * moment the player's first choice will be made in.
     */
    private function landing(Turn $turn): string
    {
        $scene = $turn->scene;
        $place = trim(($scene?->title ?? '').' — '.($scene?->description ?? ''), ' —');

        $facts = [];
        foreach ($turn->situation_board ?? [] as $group) {
            if ($group['key'] === 'self' || $group['items'] === []) {
                continue;
            }
            $facts[] = "- {$group['title']}: ".implode(', ', $group['items']);
        }
        $listed = $facts === []
            ? '- Nobody else is here. The place is empty, and that emptiness is the truth of it — do not populate it.'
            : implode("\n", $facts);

        return <<<LANDING
## Where this prologue ENDS (fixed — this is the seam, and it must not show)
The player's first choice happens in the moment immediately after your last sentence. So finish standing in it: same place, same people, same breath.

Place: {$place}
{$listed}

- End INSIDE this moment, not approaching it and not past it. The last paragraph should be here, with these people, on this ground.
- Do not resolve anything listed above. Whoever is here is still here when you stop; whatever is unsettled is still unsettled.
- Introduce every person and thing listed, naturally, as the prose arrives — the next chapter will use these names and they must not be strangers.
- Do not invent other people or places for the closing scene. If the list is empty, the character arrives alone.
LANDING;
    }

    /**
     * Write down the opening chapter. Called after the world is already open,
     * so a narrator that falls over costs the tale its prologue's polish and
     * nothing else — the campaign is live either way.
     */
    private function recordPrologue(Campaign $campaign, string $body): void
    {
        Chapter::create([
            'campaign_id' => $campaign->id,
            'turn_id' => null,
            'number' => $campaign->fresh()->nextChapterNumber(),
            'kind' => 'prologue',
            'intent_line' => null,
            'body' => $body,
        ]);
    }

    /**
     * Handle one player message.
     *
     * This never starts the campaign. The narrator's job is to keep drafting
     * — every reply carries its best current sheet, the engine prices it, and
     * the running balance goes on screen beside the conversation. The player
     * decides when they are done by pressing Begin, and until they do they
     * can keep tuning: add a burden, set a gift down, change their mind about
     * the whole shape of themselves. Auto-starting the moment the narrator
     * judged a character "complete" took that decision away, and did it in
     * the middle of a call slow enough that the player could not see it
     * happen.
     */
    public function converse(Campaign $campaign, string $playerMessage): InterviewMessage
    {
        // The player's words are spoken to the narrator before they are
        // written down: a failed CLI run must leave the transcript exactly
        // as it was, so the words stay in the player's hands to send again
        // rather than sitting in the interview forever unanswered.
        $started = microtime(true);
        $response = $this->claude->promptForJson($this->creationPrompt($campaign, $playerMessage));
        $elapsed = microtime(true) - $started;

        InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'creation',
            'role' => 'player',
            'body' => $playerMessage,
        ]);

        // The draft always replaces the last one: the Begin door opens onto
        // the sheet the player is looking at right now, never a stale one.
        $campaign->update([
            'pending_sheet' => isset($response['character']) && is_array($response['character'])
                ? $response
                : null,
        ]);

        $reply = InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'creation',
            'role' => 'narrator',
            'body' => $response['reply'] ?? '…',
            'suggestions' => $this->sanitizeSuggestions($response['suggestions'] ?? []),
        ]);

        // A slow answer is one the player may have stopped waiting for.
        if ($elapsed >= self::SLOW_REPLY_SECONDS) {
            $campaign->user->notify(new InterviewReplyNotification($campaign, $reply->body));
        }

        return $reply;
    }

    /**
     * The ledger for the sheet as it currently stands, or null before the
     * narrator has drafted one. The capabilities are clamped first, so the
     * balance on screen is the balance the engine will actually charge —
     * showing an unclamped price would teach the player a number that
     * changes under them at the last moment.
     *
     * @return array{name: ?string, description: ?string, points: int, balance: int, gifts: list<array>, burdens: list<array>, ready: bool}|null
     */
    public function draftLedger(Campaign $campaign): ?array
    {
        $sheet = $campaign->pending_sheet['character'] ?? null;
        if (! is_array($sheet)) {
            return null;
        }

        $clamped = $this->clamp->clamp($sheet['capabilities'] ?? []);
        $constraints = array_merge($sheet['constraints'] ?? [], $clamped['constraints']);
        $ledger = TraitCatalog::sheetLedger($clamped['capabilities'], $constraints);

        return $ledger + [
            'name' => $sheet['name'] ?? null,
            'description' => $sheet['description'] ?? null,
            // Balanced and actually carrying something. A sheet with no
            // gifts at all balances trivially and is nobody.
            'ready' => $ledger['balance'] >= 0 && $clamped['capabilities'] !== [],
        ];
    }

    /**
     * The player pressed Begin. Finalizes whatever sheet is on the table —
     * with `owing` when they chose to step through an overspent one, and
     * with `name` from the dedicated name field, which outranks whatever
     * the narrator drafted: the player owns their name outright, it is
     * never part of the bargain.
     */
    public function begin(Campaign $campaign, bool $owing = false, ?string $name = null): void
    {
        $pending = $campaign->pending_sheet;
        if ($pending === null || ! isset($pending['character'])) {
            return;
        }

        $name = trim((string) $name);
        if ($name !== '') {
            $pending['character']['name'] = $name;
        }

        $this->finalize($campaign, $pending, force: $owing);
    }

    /**
     * The opening chapter for a finished creation sheet — written once the
     * first scene exists, so it can end standing in it.
     */
    private function creationPrologue(Campaign $campaign, array $sheet, Turn $turn): string
    {
        $name = $sheet['name'] ?? 'The Nameless';
        $description = $sheet['description'] ?? '';
        $land = $campaign->worldBrief();
        $stage = $campaign->stageBrief();
        $stageSection = $stage === '' ? '' : "\n## The player set the stage\n{$stage}\n";
        $register = ProseStyle::rules();
        $landing = $this->landing($turn);

        try {
            return trim($this->claude->prompt(<<<PROMPT
Write the opening prologue of a living-world RPG campaign: 200-400 words, third-person past tense, narrating this character's arrival into the world. No mechanics language of any kind.

{$register}

## The land this tale is set in (fixed — the prologue happens HERE)
{$land}
{$stageSection}
## The character
{$name}: {$description}

{$landing}

Respond with ONLY the prologue prose.
PROMPT)) ?: $this->stockPrologue($name);
        } catch (\Throwable $e) {
            report($e);

            return $this->stockPrologue($name);
        }
    }

    private function finalize(Campaign $campaign, array $response, bool $force = false): void
    {
        $sheet = $response['character'];
        $clamped = $this->clamp->clamp($sheet['capabilities'] ?? []);
        $allConstraints = array_merge($sheet['constraints'] ?? [], $clamped['constraints']);
        $balance = TraitCatalog::sheetBalance($clamped['capabilities'], $allConstraints);

        // The same coin as the point-buy path: the sheet must break even
        // against the creation allowance. The running balance is on screen
        // throughout now, so reaching here overspent means someone pressed
        // Begin without choosing to step through owing — refuse, say why
        // in-world, and leave the conversation exactly where it was.
        if ($balance < 0 && ! $force) {
            InterviewMessage::create([
                'campaign_id' => $campaign->id,
                'kind' => 'creation',
                'role' => 'narrator',
                'body' => 'Not yet — the scales still refuse this bargain. Such gifts want a heavier '
                    .'price than you have named. Tell me what they truly cost you: what fails, what '
                    .'marks you, what follows you. Set one gift down — or step through regardless, '
                    .'and owe the world the difference.',
                'suggestions' => [
                    'My size betrays me — I cannot pass where smaller lives slip through, and I am remembered everywhere.',
                    'I am slow. Nothing about me moves quickly, and everyone can tell.',
                    'When blood shows, some part of me is already leaving.',
                    'Then set aside the least of my gifts — I will earn it in the world instead.',
                ],
            ]);

            return;
        }

        // Insisting has a name: the shortfall is recorded on the sheet.
        if ($balance < 0) {
            $sheet['constraints'] = array_merge($sheet['constraints'] ?? [], [TraitCatalog::debtConstraint(-$balance)]);
        }

        // The world is forged and the stage-built opening planned before the
        // transaction (slow CLI calls); a null plan falls back to the forged
        // zone's own templates.
        $this->forge->ensureStartingZone($campaign);
        $opening = $this->stage->plan($campaign, $sheet['description'] ?? '');

        $turn = DB::transaction(function () use ($campaign, $opening, $sheet, $clamped) {

            $meters = Meters::default();
            foreach ($clamped['capabilities'] as $entry) {
                $capability = Capability::tryFrom($entry['capability']);
                if ($capability?->metered()) {
                    $meters['tempo'][$capability->value] = ['current' => 2, 'max' => 3];
                }
            }

            $character = Character::create([
                'campaign_id' => $campaign->id,
                'name' => $sheet['name'] ?? 'The Nameless',
                'description' => $sheet['description'] ?? '',
                'attack_styles' => $this->attackStyles($sheet),
                'meters' => $meters,
                'status' => 'alive',
                'meters_regenerated_at' => now(),
            ]);

            foreach ($clamped['capabilities'] as $entry) {
                $character->capabilities()->create($entry + ['source' => 'creation']);
            }

            // Claude-proposed constraints (power/constraint coupling) plus
            // any the clamp re-coupled from high magnitudes.
            foreach (array_merge($sheet['constraints'] ?? [], $clamped['constraints']) as $constraint) {
                $character->constraints()->create([
                    'name' => $constraint['name'],
                    'params' => $constraint['params'] ?? null,
                    'coupled_capability' => $constraint['coupled_capability'] ?? null,
                    'source' => 'creation',
                ]);
            }

            $campaign->update(['status' => 'active', 'started_at' => now(), 'pending_sheet' => null]);

            return $this->starter->openFirstTurn($campaign, $opening);
        });

        // Written last, into the scene that now exists — see landing().
        $this->recordPrologue($campaign, $this->creationPrologue($campaign, $sheet, $turn));

        $this->announceBegun($campaign);
    }

    /**
     * The point-buy path: the player picked priced traits from the catalog
     * instead of describing themselves. The ENGINE has already validated
     * the balance and compiles the sheet; Claude is only asked to write
     * prose around the finished numbers — a description and attack styles —
     * and a failed call falls back to stock text rather than blocking the
     * birth. The prologue comes later, once there is a scene for it to end in.
     *
     * @param  list<string>  $traitKeys
     */
    public function buildFromTraits(Campaign $campaign, array $traitKeys, ?string $name): void
    {
        $build = TraitCatalog::compile($traitKeys);
        $name = trim((string) $name) ?: 'The Nameless';

        // An overspent build only reaches here via the explicit override —
        // and the shortfall walks in with them, on the record.
        $balance = TraitCatalog::balance($traitKeys);
        if ($balance < 0) {
            $build['constraints'][] = TraitCatalog::debtConstraint(-$balance);
            $build['burdens'][] = 'a debt to the world';
        }

        // Slow CLI calls run before the transaction, as everywhere else.
        $this->forge->ensureStartingZone($campaign);
        $prose = $this->traitProse($campaign, $name, $build);
        $opening = $this->stage->plan($campaign, $prose['description']);

        $turn = DB::transaction(function () use ($campaign, $build, $name, $prose, $opening) {
            $meters = Meters::default();
            $meters['health']['max'] = max(4, $meters['health']['max'] + $build['health']);
            $meters['health']['current'] = $meters['health']['max'];

            foreach ($build['capabilities'] as $entry) {
                $capability = Capability::tryFrom($entry['capability']);
                if ($capability?->metered()) {
                    $meters['tempo'][$capability->value] = ['current' => 2, 'max' => 3];
                }
            }

            $character = Character::create([
                'campaign_id' => $campaign->id,
                'name' => $name,
                'description' => $prose['description'],
                'attack_styles' => $prose['attack_styles'],
                'meters' => $meters,
                'status' => 'alive',
                'meters_regenerated_at' => now(),
            ]);

            $clamped = $this->clamp->clamp($build['capabilities']);
            foreach ($clamped['capabilities'] as $entry) {
                $character->capabilities()->create($entry + ['source' => 'creation']);
            }
            foreach (array_merge($build['constraints'], $clamped['constraints']) as $constraint) {
                $character->constraints()->create($constraint + ['source' => 'creation']);
            }

            // The choice enters the transcript, so the interview reads as a
            // finished conversation rather than stopping mid-question.
            InterviewMessage::create([
                'campaign_id' => $campaign->id, 'kind' => 'creation', 'role' => 'player',
                'body' => 'I shaped myself from the old patterns. Gifts: '.implode(', ', $build['gifts']).'.'
                    .($build['burdens'] === [] ? '' : ' Burdens: '.implode(', ', $build['burdens']).'.'),
            ]);
            InterviewMessage::create([
                'campaign_id' => $campaign->id, 'kind' => 'creation', 'role' => 'narrator',
                'body' => 'So shaped, so weighed — the world accepts the bargain. Your tale begins.',
            ]);

            $campaign->update(['status' => 'active', 'started_at' => now()]);

            return $this->starter->openFirstTurn($campaign, $opening);
        });

        // Written last, into the scene that now exists — see landing().
        $this->recordPrologue($campaign, $this->creationPrologue($campaign, [
            'name' => $name,
            'description' => $prose['description'],
        ], $turn));

        $this->announceBegun($campaign);
    }

    /**
     * Tell the player their tale opened. Beginning is the longest wait in the
     * game — three Claude calls back to back — so the tab that started it may
     * be closed, asleep, or given up on by the time it lands. A push failure
     * must never be the thing that breaks a birth that already succeeded.
     */
    private function announceBegun(Campaign $campaign): void
    {
        try {
            $campaign->refresh()->user->notify(new StoryBegunNotification($campaign));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Prose around a fixed sheet. Claude may not alter the numbers — it is
     * handed the finished traits and asked only for words.
     *
     * The prologue is deliberately NOT asked for here: it is written later,
     * once the opening scene exists, so it can end standing in it.
     *
     * @return array{description: string, attack_styles: ?list<string>}
     */
    private function traitProse(Campaign $campaign, string $name, array $build): array
    {
        $gifts = implode(', ', $build['gifts']) ?: '(none)';
        $burdens = implode(', ', $build['burdens']) ?: '(none)';
        $land = $campaign->worldBrief();
        $stage = $campaign->stageBrief();
        $stageSection = $stage === '' ? '' : "\n## The player set the stage\n{$stage}\n";
        $register = ProseStyle::rules();

        try {
            $response = $this->claude->promptForJson(<<<PROMPT
A player built their character for a living-world RPG by choosing traits from a catalog. The sheet is FIXED — do not add, remove, or reinterpret any ability. Write only the words around it.

{$register}

## The land this tale is set in (fixed — the prologue happens HERE)
{$land}
{$stageSection}

## The finished sheet
Name: {$name}
Gifts: {$gifts}
Burdens: {$burdens}

Respond with ONLY a JSON object:
{
  "description": "<2-3 sentence portrait of who this is, embodying every gift and burden, no mechanics language>",
  "attack_styles": <3-6 short phrases for how this body attacks, fitted to the gifts, e.g. "a driving shoulder", "a lash of the long limb">
}
PROMPT);

            return [
                'description' => trim((string) ($response['description'] ?? '')) ?: $this->stockDescription($name, $build),
                'attack_styles' => $this->attackStyles(['attack_styles' => $response['attack_styles'] ?? []]),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'description' => $this->stockDescription($name, $build),
                'attack_styles' => null,
            ];
        }
    }

    private function stockDescription(string $name, array $build): string
    {
        $gifts = implode(', ', array_map('strtolower', $build['gifts'])) ?: 'no gift but stubbornness';
        $burdens = $build['burdens'] === [] ? '' : ', carrying '.implode(', ', array_map('strtolower', $build['burdens']));

        return "{$name}: marked by {$gifts}{$burdens}.";
    }

    private function stockPrologue(string $name): string
    {
        return "{$name} stepped into the waiting world exactly as they had made themselves — "
            .'gifts in one hand, their price in the other. Somewhere ahead, a story was already making room.';
    }

    /**
     * Sanitize Claude-proposed answer suggestions: short strings only,
     * capped at four; null hides the chip row entirely.
     *
     * @return list<string>|null
     */
    private function sanitizeSuggestions(mixed $suggestions): ?array
    {
        $clean = collect(is_array($suggestions) ? $suggestions : [])
            ->filter(fn ($s) => is_string($s) && trim($s) !== '')
            ->map(fn (string $s) => mb_substr(trim($s), 0, 200))
            ->unique()
            ->take(4)
            ->values()
            ->all();

        return $clean === [] ? null : $clean;
    }

    /**
     * Sanitize Claude-proposed attack styles: short strings only, capped at
     * six. Null (rather than an empty list) lets the composer fall back to
     * its body-neutral defaults.
     *
     * @return list<string>|null
     */
    private function attackStyles(array $sheet): ?array
    {
        $styles = collect($sheet['attack_styles'] ?? [])
            ->filter(fn ($s) => is_string($s) && trim($s) !== '')
            ->map(fn (string $s) => mb_substr(trim($s), 0, 40))
            ->unique()
            ->take(6)
            ->values()
            ->all();

        return $styles === [] ? null : $styles;
    }

    /**
     * @param  string|null  $pending  The player's newest words, not yet written
     *                                to the transcript (they are committed only
     *                                once the narrator has answered).
     */
    private function creationPrompt(Campaign $campaign, ?string $pending = null): string
    {
        $lines = $campaign->interviewMessages()->where('kind', 'creation')->orderBy('id')->get()
            ->map(fn (InterviewMessage $m) => strtoupper($m->role).": {$m->body}")
            ->all();

        if ($pending !== null) {
            $lines[] = "PLAYER: {$pending}";
        }

        $transcript = implode("\n\n", $lines);

        $vocabulary = collect(Capability::cases())
            ->map(fn ($c) => $c->value.($c->parameterized() ? '(n)' : ''))
            ->join(', ');

        $land = $campaign->worldBrief();
        $stage = $campaign->stageBrief();
        $stageSection = $stage === '' ? '' : "\n## The player set the stage (speak and shape the prologue in its spirit)\n{$stage}\n";
        $points = TraitCatalog::startingPoints();
        $prices = TraitCatalog::priceSheetForPrompt();

        return <<<PROMPT
You are conducting an in-world character creation interview for a living-world RPG. The player describes their character narratively; you translate it under the hood into a clean structured loadout. Ask one short, evocative question per reply.

You do NOT decide when the interview ends — the player does, by pressing Begin. Your job is to keep a good draft of the character on the table at ALL times, and to keep helping them tune it for as long as they want to talk. Even after they seem finished, answer any further message by refining the same character rather than starting a new one: they may be adjusting the bargain, and the engine shows them their running balance while they do.

## The land this tale is set in (fixed — your questions and the prologue belong here)
{$land}
{$stageSection}

Rules:
- The character's NAME is set by the player in a dedicated field outside this conversation — never ask what they are called. If they volunteer a name in their words, put it on the sheet; otherwise leave your best working name and move on. It is display-only either way: the field wins at the end.
- Capabilities must come from this vocabulary: {$vocabulary}
- Every strong capability should drag a constraint with it (power/constraint coupling). Example: large intimidating size → cannot squeeze through narrow gaps, stealth penalty, breaks fragile surfaces.
- HARD BUDGET (engine-enforced; the player can SEE this balance on screen while you talk): the sheet starts with {$points} points. {$prices} Balance the draft at zero or better — a gift-heavy character MUST carry real constraints to pay for it. Weave the accounting into your questions in-world ("every gift leaves a debt — where does yours come due?"), never as numbers. If the draft is currently overspent, your next question should be about the price, not about more gifts.
- Magnitudes are clamped by the engine regardless of what you write; keep them modest (reach ≤ 15, lift ≤ 250 at creation).
- Scoped social powers: e.g. intimidate should carry {"vs": "regular"} so it does not flatten elite encounters.
- attack_styles: 3-6 short phrases for how this body attacks (e.g. "a bite", "a rake of claws", "a tail-whip", "a shoulder-slam"). Narration vocabulary only — they never change outcomes.

## Transcript so far
{$transcript}

Respond with ONLY a JSON object:
{
  "reply": "<your next in-world line to the player>",
  "suggestions": <3-4 example answers to YOUR question, each in the PLAYER's voice and sendable exactly as written — one plain sentence, ≤ 160 characters. Pull them in genuinely different directions (different bodies, prices, temperaments), so a stuck player discovers what kinds of answers are possible.>,
  "character": <ALWAYS your best current draft, never null — even after one exchange, even if half-guessed. This is what the player sees priced on screen and what they step into the world as if they press Begin now, so it must always be a playable sheet: {"name": "...", "description": "<2-3 sentence distillation>", "attack_styles": ["a bite", "a rake of claws", ...], "capabilities": [{"capability": "reach", "magnitude": 12, "grade": null, "scope": null}, ...], "constraints": [{"name": "stealth_penalty", "params": {"size": "large"}, "coupled_capability": "intimidate"}, ...]}>
}

Keep this reply SHORT. The player is waiting on it in a live conversation, and there may be many exchanges before they are done — no prologue, no long prose, just the question and the draft behind it.
PROMPT;
    }

    /**
     * Evolution: same narrated-request mechanic, Claude as in-world limiter.
     *
     * The verdict is recorded alongside the answer, and this is the whole
     * point of the columns. A refusal and a grant used to arrive as the same
     * thing — one line of in-world prose, gone as soon as the page reloaded —
     * so a player who asked for something and got a graceful "not yet" could
     * not tell it apart from a player whose sheet had just changed, or from a
     * request that had simply vanished. What changed is now written down in
     * the engine's own words, because Claude's prose is deliberately vague
     * about numbers and the sheet is not.
     */
    public function grow(Campaign $campaign, string $request): InterviewMessage
    {
        $character = $campaign->character;

        $sheet = $character->capabilities->map(fn ($c) => $c->only(['capability', 'magnitude', 'grade', 'scope']))->toJson();
        $constraints = $character->constraints->map(fn ($c) => $c->only(['name', 'params', 'coupled_capability']))->toJson();
        $bounds = json_encode(config('game.bounds.capability_magnitudes'));
        $vocabulary = implode(', ', array_column(Capability::cases(), 'value'));
        $transcript = $this->growthTranscript($campaign);

        $response = $this->claude->promptForJson(<<<PROMPT
A player asks to evolve their character. Either translate the request into a small capability/magnitude change, or push back in-world if it overreaches ("your tail can hold one more item, but lifting a grown person is beyond it — perhaps with training, later"). One change at a time; deepening an existing magnitude is cheaper than a new capability.

Capabilities must come from this vocabulary: {$vocabulary}
Current capabilities: {$sheet}
Current constraints: {$constraints}
Hard bounds (engine-enforced): {$bounds}
{$transcript}
Player's request: {$request}

Respond with ONLY a JSON object:
{
  "reply": "<in-world answer — say plainly whether the world grants this, and what it costs>",
  "granted": <bool>,
  "changes": <null or [{"capability": "...", "magnitude": <int|null>, "grade": <string|null>, "scope": <object|null>}]>,
  "suggestions": <2-4 things the player could ask for NEXT, each in the player's own voice, one plain sentence, ≤ 120 characters — a refusal should suggest the smaller version of what they wanted>
}
PROMPT);

        // Written down only once the world has answered — same bargain as
        // the creation interview.
        InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'growth',
            'role' => 'player',
            'body' => $request,
        ]);

        // What the ENGINE did, not what Claude said it did. The clamp may
        // have cut a proposed magnitude down, or dragged a new constraint in
        // behind a strong gift — the player is owed the real ledger.
        $applied = [];
        if (($response['granted'] ?? false) && is_array($response['changes'] ?? null)) {
            $clamped = $this->clamp->clamp($response['changes']);
            foreach ($clamped['capabilities'] as $entry) {
                $before = $character->capabilities()->where('capability', $entry['capability'])->first();
                $character->capabilities()->updateOrCreate(
                    ['capability' => $entry['capability']],
                    $entry + ['source' => 'growth'],
                );
                $applied[] = [
                    'kind' => 'gift',
                    'label' => str_replace('_', ' ', $entry['capability']),
                    'detail' => $this->magnitudeChange($before?->magnitude, $entry['magnitude'] ?? null, $entry['grade'] ?? null),
                ];
            }
            foreach ($clamped['constraints'] as $constraint) {
                $fresh = $character->constraints()->firstOrCreate(
                    ['name' => $constraint['name']],
                    $constraint + ['source' => 'growth'],
                );
                if ($fresh->wasRecentlyCreated) {
                    $applied[] = [
                        'kind' => 'burden',
                        'label' => str_replace('_', ' ', $constraint['name']),
                        'detail' => 'the price this gift drags with it',
                    ];
                }
            }
        }

        // Claude can say yes and change nothing — an unusable capability name,
        // a magnitude already held. The sheet is the authority on whether this
        // was a grant, so the record says what actually moved.
        $granted = $applied !== [];

        return InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'growth',
            'role' => 'narrator',
            'body' => $response['reply'] ?? '…',
            'granted' => $granted,
            'changes' => $applied,
            'suggestions' => array_values(array_filter(
                array_map('strval', $response['suggestions'] ?? []),
                fn (string $s) => trim($s) !== '',
            )),
        ]);
    }

    /**
     * The evolution conversation so far. Asking twice for the same thing
     * should meet the same answer, and a world that has already said "not
     * until you have carried it a while" needs to remember saying it.
     */
    private function growthTranscript(Campaign $campaign): string
    {
        $lines = $campaign->interviewMessages()
            ->where('kind', 'growth')->orderBy('id')->get()->take(-8)
            ->map(fn (InterviewMessage $m) => ($m->role === 'player' ? 'PLAYER: ' : 'WORLD: ').$m->body)
            ->join("\n");

        return $lines === '' ? '' : "\n## Earlier in this conversation\n{$lines}\n";
    }

    private function magnitudeChange(?int $before, ?int $after, ?string $grade): string
    {
        if ($after === null) {
            return $grade === null ? 'newly yours' : "newly yours ({$grade})";
        }

        if ($before === null) {
            return "newly yours, at {$after}";
        }

        return $before === $after ? "held at {$after}" : "{$before} → {$after}";
    }
}
