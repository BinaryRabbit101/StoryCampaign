<?php

namespace App\Services\Claude;

use App\Models\Chapter;
use App\Models\Turn;
use App\Notifications\TurnReadyNotification;
use Illuminate\Support\Facades\File;

/**
 * Turn-resolution narration. The engine has already decided WHETHER
 * everything happened; this service asks Claude to weave those resolved
 * outcomes into one continuous chapter, persists it (the book's raw
 * material), and pushes the new-situation notification.
 */
class Narrator
{
    public function __construct(private readonly ClaudeCli $claude) {}

    public function narrate(Turn $turn): Chapter
    {
        $campaign = $turn->campaign;
        $character = $campaign->character;

        $response = $this->claude->promptForJson($this->buildPrompt($turn), $this->tone());

        $chapter = Chapter::create([
            'campaign_id' => $campaign->id,
            'turn_id' => $turn->id,
            'number' => $campaign->nextChapterNumber(),
            'kind' => 'chapter',
            'intent_line' => $response['intent_line'] ?? null,
            'body' => $response['chapter'] ?? throw new \RuntimeException('Narration missing chapter body.'),
        ]);

        $turn->update(['narrated_at' => now()]);

        $next = $campaign->turns()->where('status', Turn::STATUS_AWAITING)->orderByDesc('number')->first();
        $campaign->user->notify(new TurnReadyNotification($campaign, $chapter, $next));

        return $chapter;
    }

    private function buildPrompt(Turn $turn): string
    {
        $campaign = $turn->campaign;
        $character = $campaign->character;
        $resolution = $turn->resolution;
        $submission = $turn->submission ?? [];

        $previousChapters = $campaign->chapters()->orderByDesc('number')->limit(2)->get()
            ->reverse()
            ->map(fn (Chapter $c) => "### Chapter {$c->number}\n".mb_substr($c->body, -1200))
            ->join("\n\n");

        $beats = collect($resolution['beats'] ?? [])
            ->map(function (array $beat) {
                $status = $beat['skipped'] ? 'DID NOT HAPPEN' : strtoupper($beat['degree']);
                $facts = implode(' ', $beat['facts']);

                return "- [{$beat['slot']}] {$beat['verb']} → {$status}. {$facts}";
            })->join("\n");

        $reaction = collect($resolution['scene_reaction'] ?? [])->map(fn ($f) => "- {$f}")->join("\n");
        if (isset($resolution['new_threat']['name'])) {
            $reaction .= ($reaction === '' ? '' : "\n")
                ."- {$resolution['new_threat']['name']} arrived mid-scene — introduce this newcomer before the chapter ends.";
        }
        $intent = $submission['intent_text'] ?? null;

        return <<<PROMPT
You are the narrator of a living-world RPG. Write the next chapter of this campaign as flowing third-person past-tense prose, weaving the ENGINE-RESOLVED beats below into one continuous vignette. You decide how things happened, never whether: every fact listed is fixed. Do not mention dice, rolls, cards, slots, meters, or any mechanics.

## Character
{$character->name}: {$character->description}

## Recent chapters (for continuity)
{$previousChapters}

## Player's optional intent line (flavor only — it cannot change outcomes)
{$intent}

## Engine-resolved beats of this vignette (fixed facts, in order)
{$beats}

## How the scene answered
{$reaction}

## Where the vignette stops
{$turn->branch_trigger}: the chapter must end on this note, at a clean decision point, leaving the situation open for the player's next choice. 300-600 words.

Respond with ONLY a JSON object:
{"intent_line": "<optional short italicized bridge line in the style of 'She chose to take the rooftops.' or null>", "chapter": "<the chapter prose>"}
PROMPT;
    }

    private function tone(): string
    {
        $biblePath = config('game.design_bible_path');
        $bible = File::exists($biblePath) ? File::get($biblePath) : '';

        return "Honor this design bible (tone and themes only):\n\n".mb_substr($bible, 0, 4000);
    }
}
