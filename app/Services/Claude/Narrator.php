<?php

namespace App\Services\Claude;

use App\Game\Engine\ChapterEvents;
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

        $previousChapters = $campaign->chapters()->reorder('number', 'desc')->limit(2)->get()
            ->reverse()
            ->map(fn (Chapter $c) => "### Chapter {$c->number}\n".mb_substr($c->plainBody(), -1200))
            ->join("\n\n");

        $events = collect(ChapterEvents::for($turn));
        $beats = $events->filter(fn ($e) => $e['verb'] !== null)->map(ChapterEvents::promptLine(...))->join("\n");
        $reaction = $events->filter(fn ($e) => $e['verb'] === null)->map(ChapterEvents::promptLine(...))->join("\n");
        if (isset($resolution['new_threat']['name'])) {
            $reaction .= "\n(Introduce this newcomer before the chapter ends.)";
        }
        $intent = $submission['intent_text'] ?? null;

        // The word budget scales with what actually happened: a one-beat
        // vignette gets a tight page, never 600 words of padding stretched
        // over a single act.
        $eventCount = max(1, $events->count());
        $wordLow = min(120, 60 + 20 * $eventCount);
        $wordHigh = min(520, 130 + 90 * $eventCount);

        // The next turn already exists (the engine opened it during resolution).
        // Its situation is resolved fact, so the chapter may close inside it —
        // minus the meter readout, which is mechanics and never reaches prose.
        $nextTurn = $campaign->turns()->where('status', Turn::STATUS_AWAITING)->orderByDesc('number')->first();
        $aftermath = $nextTurn === null ? 'Unknown — end where the beats leave off.'
            : trim(preg_replace('/\s*Health \d+\/\d+\./', '', $nextTurn->situation));
        $stage = $campaign->stageBrief() ?: '(none set — let the tale find its own direction)';

        return <<<PROMPT
You are the narrator of a living-world RPG. Write the next chapter of this campaign as flowing third-person past-tense prose, weaving the ENGINE-RESOLVED beats below into one continuous vignette. You decide how things happened, never whether: every fact listed is fixed. Do not mention dice, rolls, cards, slots, meters, or any mechanics.

## Style: action over atmosphere
- The beats carry the chapter. Every paragraph must have something HAPPENING — movement, contact, exchange, consequence.
- Description rides inside the action: a clause on the way through a beat, never a standalone scene-painting paragraph. If a detail doesn't change what someone does or feels next, cut it.
- Prefer concrete verbs to stacked adjectives. Short sentences in the thick of it; longer ones only as the dust settles.
- Open inside motion or intent — never with the weather, the light, or the skyline.
- Match length to substance: few beats mean a short chapter. Never pad toward a word count — a tight half-page beats a stretched full one.

Each listed event carries a bracketed token like [[e1]]. Copy every token into the chapter VERBATIM, each exactly once, placed immediately after the sentence where that event lands in the prose. The tokens are invisible anchors in the reader's edition — never mention them, never describe them, never invent new ones.

## Character
{$character->name}: {$character->description}

## The stage the player set (color and direction only — never force the goal closed; the player decides when it is met)
{$stage}

## Recent chapters (for continuity)
{$previousChapters}

## Player's optional intent line (flavor only — it cannot change outcomes)
{$intent}

## Engine-resolved beats of this vignette (fixed facts, in order)
{$beats}

## How the scene answered
{$reaction}

## Where the vignette stops
{$turn->branch_trigger}: the chapter must end on this note, at a clean decision point, leaving the situation open for the player's next choice. {$wordLow}-{$wordHigh} words.

## The state of play as the chapter closes (fixed facts)
{$aftermath}

The reader makes their next choice from the page itself: close the chapter inside this moment, with the people and surroundings named above present in the prose, so nothing the player can act on appears unannounced. Do not summarize or list them — let the scene hold them naturally.

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
