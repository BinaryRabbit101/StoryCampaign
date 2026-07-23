<?php

namespace App\Services\Claude;

use App\Game\Capability;
use App\Game\Meters;
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
        // prose rather than failing the campaign.
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

        $response = $this->claude->promptForJson($this->creationPrompt($campaign));

        $reply = InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'creation',
            'role' => 'narrator',
            'body' => $response['reply'] ?? '…',
        ]);

        if (($response['complete'] ?? false) === true && isset($response['character'])) {
            $this->finalize($campaign, $response);
        }

        return $reply;
    }

    private function finalize(Campaign $campaign, array $response): void
    {
        // The stage-built opening runs before the transaction (slow CLI call);
        // null falls back to the zone's spawn templates.
        $opening = $this->stage->plan($campaign, $response['character']['description'] ?? '');

        DB::transaction(function () use ($campaign, $response, $opening) {
            $sheet = $response['character'];
            $clamped = $this->clamp->clamp($sheet['capabilities'] ?? []);

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

            $campaign->update(['status' => 'active', 'started_at' => now()]);

            $this->starter->openFirstTurn($campaign, $opening);
        });
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

        return <<<PROMPT
You are conducting an in-world character creation interview for a living-world RPG. The player describes their character narratively; you translate it under the hood into a clean structured loadout. Ask at most a few short, evocative questions (one per reply). After the player has given enough (usually 2-4 exchanges), complete the interview.
{$stageSection}

Rules:
- Capabilities must come from this vocabulary: {$vocabulary}
- Every strong capability should drag a constraint with it (power/constraint coupling). Example: large intimidating size → cannot squeeze through narrow gaps, stealth penalty, breaks fragile surfaces.
- Magnitudes are clamped by the engine regardless of what you write; keep them modest (reach ≤ 15, lift ≤ 250 at creation).
- Scoped social powers: e.g. intimidate should carry {"vs": "regular"} so it does not flatten elite encounters.

## Transcript so far
{$transcript}

Respond with ONLY a JSON object:
{
  "reply": "<your next in-world line to the player>",
  "complete": <true only when the character is fully formed>,
  "character": <null until complete, then: {"name": "...", "description": "<2-3 sentence distillation>", "capabilities": [{"capability": "reach", "magnitude": 12, "grade": null, "scope": null}, ...], "constraints": [{"name": "stealth_penalty", "params": {"size": "large"}, "coupled_capability": "intimidate"}, ...]}>,
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
