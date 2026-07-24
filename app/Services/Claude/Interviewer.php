<?php

namespace App\Services\Claude;

use App\Game\Capability;
use App\Game\Meters;
use App\Game\TraitCatalog;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\InterviewMessage;
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
        $prologue = $this->returningPrologue($campaign, $original);
        $opening = $this->stage->plan($campaign, $original->description);

        DB::transaction(function () use ($campaign, $original, $prologue, $opening) {
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

            Chapter::create([
                'campaign_id' => $campaign->id,
                'turn_id' => null,
                'number' => $campaign->nextChapterNumber(),
                'kind' => 'prologue',
                'intent_line' => null,
                'body' => $prologue,
            ]);

            $campaign->update(['status' => 'active', 'started_at' => now()]);

            $this->starter->openFirstTurn($campaign, $opening);
        });
    }

    private function returningPrologue(Campaign $campaign, Character $original): string
    {
        $previous = $original->campaign;
        $lastChapter = $previous?->chapters()->reorder('number', 'desc')->first();
        $closing = $lastChapter === null ? '(their earlier tale is unrecorded)' : mb_substr($lastChapter->plainBody(), -800);
        $stage = $campaign->stageBrief();
        $stageSection = $stage === '' ? '' : "\n## The player set the stage for this new tale\n{$stage}\n";

        try {
            return $this->claude->prompt(<<<PROMPT
A hero returns for a new tale in a living-world RPG. Write a 200-400 word prologue in third-person past tense: {$original->name} steps out of an earlier story and into this new one, "{$campaign->name}". Carry the weight of where their last tale left off, but open cleanly — a new book, not a recap. No mechanics language.
{$stageSection}

## The character
{$original->name}: {$original->description}

## Where their last tale ("{$previous?->name}") left them
{$closing}

Respond with ONLY the prologue prose.
PROMPT);
        } catch (\Throwable $e) {
            report($e);

            return "{$original->name} stepped once more into the waiting world. What was learned in the last tale came along; what was lost stayed lost. Somewhere ahead, a new story was already making room.";
        }
    }

    /** Handle one player message; may complete the interview and start the campaign. */
    public function converse(Campaign $campaign, string $playerMessage): InterviewMessage
    {
        InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'creation',
            'role' => 'player',
            'body' => $playerMessage,
        ]);

        // Speaking again withdraws any refused sheet: the insist door only
        // ever opens onto the sheet the world just weighed, never a stale one.
        if ($campaign->pending_sheet !== null) {
            $campaign->update(['pending_sheet' => null]);
        }

        $response = $this->claude->promptForJson($this->creationPrompt($campaign));

        $reply = InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'creation',
            'role' => 'narrator',
            'body' => $response['reply'] ?? '…',
            'suggestions' => ($response['complete'] ?? false) ? null
                : $this->sanitizeSuggestions($response['suggestions'] ?? []),
        ]);

        if (($response['complete'] ?? false) === true && isset($response['character'])) {
            $this->finalize($campaign, $response);
        }

        return $reply;
    }

    private function finalize(Campaign $campaign, array $response, bool $force = false): void
    {
        $sheet = $response['character'];
        $clamped = $this->clamp->clamp($sheet['capabilities'] ?? []);
        $allConstraints = array_merge($sheet['constraints'] ?? [], $clamped['constraints']);
        $balance = TraitCatalog::sheetBalance($clamped['capabilities'], $allConstraints);

        // The same coin as the point-buy path: the interview's sheet must
        // break even against the creation allowance. When the bargain runs
        // short, the world refuses in-world and the interview continues —
        // the player names a real price, sets a gift down, or insists and
        // steps in owing (the refused sheet parks on the campaign for that).
        if ($balance < 0 && ! $force) {
            $campaign->update(['pending_sheet' => $response]);

            InterviewMessage::create([
                'campaign_id' => $campaign->id,
                'kind' => 'creation',
                'role' => 'narrator',
                'body' => 'The world weighs what you ask, and the scales refuse it — such gifts want a '
                    .'heavier price than you have named. Tell me what they truly cost you: what fails, '
                    .'what marks you, what follows you. Set one gift down — or step through regardless, '
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

        DB::transaction(function () use ($campaign, $response, $opening, $sheet, $clamped) {

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

            Chapter::create([
                'campaign_id' => $campaign->id,
                'turn_id' => null,
                'number' => $campaign->nextChapterNumber(),
                'kind' => 'prologue',
                'intent_line' => null,
                'body' => $response['prologue'] ?? $response['reply'] ?? '',
            ]);

            $campaign->update(['status' => 'active', 'started_at' => now(), 'pending_sheet' => null]);

            $this->starter->openFirstTurn($campaign, $opening);
        });
    }

    /**
     * The override: the player steps into the world with the sheet the
     * scales refused, unbalanced and owing. The shortfall is recorded as a
     * debt_to_the_world constraint by the forced finalize.
     */
    public function insist(Campaign $campaign): void
    {
        $pending = $campaign->pending_sheet;
        if ($pending === null || ! isset($pending['character'])) {
            return;
        }

        InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'creation',
            'role' => 'player',
            'body' => 'I step through regardless. Whatever is owed, the world may come and collect.',
        ]);

        $this->finalize($campaign, $pending, force: true);
    }

    /**
     * The point-buy path: the player picked priced traits from the catalog
     * instead of describing themselves. The ENGINE has already validated
     * the balance and compiles the sheet; Claude is only asked to write
     * prose around the finished numbers — a description, attack styles,
     * and a prologue — and a failed call falls back to stock text rather
     * than blocking the birth.
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

        DB::transaction(function () use ($campaign, $build, $name, $prose, $opening) {
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

            Chapter::create([
                'campaign_id' => $campaign->id,
                'turn_id' => null,
                'number' => $campaign->nextChapterNumber(),
                'kind' => 'prologue',
                'intent_line' => null,
                'body' => $prose['prologue'],
            ]);

            $campaign->update(['status' => 'active', 'started_at' => now()]);

            $this->starter->openFirstTurn($campaign, $opening);
        });
    }

    /**
     * Prose around a fixed sheet. Claude may not alter the numbers — it is
     * handed the finished traits and asked only for words.
     *
     * @return array{description: string, attack_styles: ?list<string>, prologue: string}
     */
    private function traitProse(Campaign $campaign, string $name, array $build): array
    {
        $gifts = implode(', ', $build['gifts']) ?: '(none)';
        $burdens = implode(', ', $build['burdens']) ?: '(none)';
        $stage = $campaign->stageBrief();
        $stageSection = $stage === '' ? '' : "\n## The player set the stage\n{$stage}\n";

        try {
            $response = $this->claude->promptForJson(<<<PROMPT
A player built their character for a living-world RPG by choosing traits from a catalog. The sheet is FIXED — do not add, remove, or reinterpret any ability. Write only the words around it.
{$stageSection}

## The finished sheet
Name: {$name}
Gifts: {$gifts}
Burdens: {$burdens}

Respond with ONLY a JSON object:
{
  "description": "<2-3 sentence portrait of who this is, embodying every gift and burden, no mechanics language>",
  "attack_styles": <3-6 short phrases for how this body attacks, fitted to the gifts, e.g. "a driving shoulder", "a lash of the long limb">,
  "prologue": "<200-400 word prologue chapter narrating this character's arrival into the world, third-person past tense, no mechanics>"
}
PROMPT);

            return [
                'description' => trim((string) ($response['description'] ?? '')) ?: $this->stockDescription($name, $build),
                'attack_styles' => $this->attackStyles(['attack_styles' => $response['attack_styles'] ?? []]),
                'prologue' => trim((string) ($response['prologue'] ?? '')) ?: $this->stockPrologue($name),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'description' => $this->stockDescription($name, $build),
                'attack_styles' => null,
                'prologue' => $this->stockPrologue($name),
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

    private function creationPrompt(Campaign $campaign): string
    {
        $transcript = $campaign->interviewMessages()->orderBy('id')->get()
            ->map(fn (InterviewMessage $m) => strtoupper($m->role).": {$m->body}")
            ->join("\n\n");

        $vocabulary = collect(Capability::cases())
            ->map(fn ($c) => $c->value.($c->parameterized() ? '(n)' : ''))
            ->join(', ');

        $stage = $campaign->stageBrief();
        $stageSection = $stage === '' ? '' : "\n## The player set the stage (speak and shape the prologue in its spirit)\n{$stage}\n";
        $points = TraitCatalog::startingPoints();
        $prices = TraitCatalog::priceSheetForPrompt();

        return <<<PROMPT
You are conducting an in-world character creation interview for a living-world RPG. The player describes their character narratively; you translate it under the hood into a clean structured loadout. Ask at most a few short, evocative questions (one per reply). After the player has given enough (usually 2-4 exchanges), complete the interview.
{$stageSection}

Rules:
- Capabilities must come from this vocabulary: {$vocabulary}
- Every strong capability should drag a constraint with it (power/constraint coupling). Example: large intimidating size → cannot squeeze through narrow gaps, stealth penalty, breaks fragile surfaces.
- HARD BUDGET (engine-enforced; an overspent sheet is refused and the interview continues): the sheet starts with {$points} points. {$prices} Balance the finished sheet at zero or better — a gift-heavy character MUST carry real constraints to pay for it. Weave the accounting into your questions in-world ("every gift leaves a debt — where does yours come due?"), never as numbers.
- Magnitudes are clamped by the engine regardless of what you write; keep them modest (reach ≤ 15, lift ≤ 250 at creation).
- Scoped social powers: e.g. intimidate should carry {"vs": "regular"} so it does not flatten elite encounters.
- attack_styles: 3-6 short phrases for how this body attacks (e.g. "a bite", "a rake of claws", "a tail-whip", "a shoulder-slam"). Narration vocabulary only — they never change outcomes.

## Transcript so far
{$transcript}

Respond with ONLY a JSON object:
{
  "reply": "<your next in-world line to the player>",
  "suggestions": <3-4 example answers to YOUR question, each in the PLAYER's voice and sendable exactly as written — one plain sentence, ≤ 160 characters. Pull them in genuinely different directions (different bodies, prices, temperaments), so a stuck player discovers what kinds of answers are possible. Empty array when complete is true.>,
  "complete": <true only when the character is fully formed>,
  "character": <null until complete, then: {"name": "...", "description": "<2-3 sentence distillation>", "attack_styles": ["a bite", "a rake of claws", ...], "capabilities": [{"capability": "reach", "magnitude": 12, "grade": null, "scope": null}, ...], "constraints": [{"name": "stealth_penalty", "params": {"size": "large"}, "coupled_capability": "intimidate"}, ...]}>,
  "prologue": <null until complete, then a 200-400 word prologue chapter narrating this character's birth into the world, third-person past tense, no mechanics>
}
PROMPT;
    }

    /** Growth: same narrated-request mechanic, Claude as in-world limiter. */
    public function grow(Campaign $campaign, string $request): InterviewMessage
    {
        $character = $campaign->character;

        InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'growth',
            'role' => 'player',
            'body' => $request,
        ]);

        $sheet = $character->capabilities->map(fn ($c) => $c->only(['capability', 'magnitude', 'grade', 'scope']))->toJson();
        $constraints = $character->constraints->map(fn ($c) => $c->only(['name', 'params', 'coupled_capability']))->toJson();
        $bounds = json_encode(config('game.bounds.capability_magnitudes'));

        $response = $this->claude->promptForJson(<<<PROMPT
A player asks to grow their character. Either translate the request into a small capability/magnitude change, or push back in-world if it overreaches ("your tail can hold one more item, but lifting a grown person is beyond it — perhaps with training, later"). One change at a time; deepening an existing magnitude is cheaper than a new capability.

Current capabilities: {$sheet}
Current constraints: {$constraints}
Hard bounds (engine-enforced): {$bounds}

Player's request: {$request}

Respond with ONLY a JSON object:
{"reply": "<in-world answer>", "granted": <bool>, "changes": <null or [{"capability": "...", "magnitude": <int|null>, "grade": <string|null>, "scope": <object|null>}]>}
PROMPT);

        if (($response['granted'] ?? false) && is_array($response['changes'] ?? null)) {
            $clamped = $this->clamp->clamp($response['changes']);
            foreach ($clamped['capabilities'] as $entry) {
                $character->capabilities()->updateOrCreate(
                    ['capability' => $entry['capability']],
                    $entry + ['source' => 'growth'],
                );
            }
            foreach ($clamped['constraints'] as $constraint) {
                $character->constraints()->firstOrCreate(
                    ['name' => $constraint['name']],
                    $constraint + ['source' => 'growth'],
                );
            }
        }

        return InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'growth',
            'role' => 'narrator',
            'body' => $response['reply'] ?? '…',
        ]);
    }
}
