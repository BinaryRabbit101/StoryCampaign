<?php

namespace App\Services\Claude;

use App\Game\Engine\ChapterEvents;
use App\Models\Chapter;
use App\Models\Turn;
use App\Notifications\TurnReadyNotification;
use App\Services\PlayerPresence;
use Illuminate\Support\Facades\File;

/**
 * Turn-resolution narration. The engine has already decided WHETHER
 * everything happened; this service asks Claude to weave those resolved
 * outcomes into one continuous chapter, persists it (the book's raw
 * material), and pushes the new-situation notification.
 */
class Narrator
{
    public function __construct(
        private readonly ClaudeCli $claude,
        private readonly ZoneForge $forge,
    ) {}

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

        // Frontier growth rides the narration job — the world forges its
        // next zone off the player's clock, and a failure here costs only
        // the wait until the next chapter tries again.
        try {
            $this->forge->ensureFrontierZone($campaign->fresh());
        } catch (\Throwable $e) {
            report($e);
        }

        // The push is for the player who left, not the one still here. A
        // chapter that lands on a page they are already watching arrives on
        // that page by itself a beat later; buzzing as well just teaches them
        // that the notification means nothing.
        if (! PlayerPresence::isWatching($campaign)) {
            $next = $campaign->turns()->where('status', Turn::STATUS_AWAITING)->orderByDesc('number')->first();
            $campaign->user->notify(new TurnReadyNotification($campaign, $chapter, $next));
        }

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
        // The player's words now travel per beat (see the beat listing below);
        // this whole-turn line survives only for turns committed before that,
        // and drops out of the prompt entirely when there is none.
        $intent = trim((string) ($submission['intent_text'] ?? ''));
        $intent = $intent === '' ? ''
            : "\n## Player's optional intent line (flavor only — it cannot change outcomes)\n{$intent}\n";

        // The word budget scales with what actually happened: a one-beat
        // vignette gets a tight page, never 600 words of padding stretched
        // over a single act.
        $eventCount = max(1, $events->count());
        $wordLow = min(120, 60 + 20 * $eventCount);
        $wordHigh = min(520, 130 + 90 * $eventCount);

        // A crit is the chapter's spine, and a spine needs room. Telling the
        // narrator to make a moment enormous inside a budget that has no
        // space left for it just produces a crowded page.
        $criticals = $this->criticalMoments($turn);
        if ($criticals !== '') {
            $wordLow += 60;
            $wordHigh = min(640, $wordHigh + 140);
        }

        // The next turn already exists (the engine opened it during resolution).
        // Its situation is resolved fact, so the chapter may close inside it —
        // minus the meter readout, which is mechanics and never reaches prose.
        $nextTurn = $campaign->turns()->where('status', Turn::STATUS_AWAITING)->orderByDesc('number')->first();
        $aftermath = $nextTurn === null ? 'Unknown — end where the beats leave off.'
            : trim(preg_replace('/\s*Health \d+\/\d+\./', '', $nextTurn->situation));
        $land = $campaign->worldBrief();
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

## The land this tale walks (fixed — every image, name, and smell belongs here)
{$land}

## Character
{$character->name}: {$character->description}

## The stage the player set (color and direction only — never force the goal closed; the player decides when it is met)
{$stage}

## Recent chapters (for continuity)
{$previousChapters}

{$intent}
## Engine-resolved beats of this vignette (fixed facts, in order)
Some beats carry the player's own words for that moment. Those words are voice and flavor: honor their spirit in how you tell the beat, and never let them alter what the beat's facts say happened.
{$beats}

## How the scene answered
{$reaction}
{$criticals}
## Where the vignette stops
{$turn->branch_trigger}: the chapter must end on this note, at a clean decision point, leaving the situation open for the player's next choice. {$wordLow}-{$wordHigh} words.

## The state of play as the chapter closes (fixed facts)
{$aftermath}

The reader makes their next choice from the page itself: close the chapter inside this moment, with the people and surroundings named above present in the prose, so nothing the player can act on appears unannounced. Do not summarize or list them — let the scene hold them naturally.

Respond with ONLY a JSON object:
{"intent_line": "<optional short italicized bridge line in the style of 'She chose to take the rooftops.' or null>", "chapter": "<the chapter prose>"}
PROMPT;
    }

    /**
     * The crit block.
     *
     * A natural 20 or a natural 1 is not a beat with a better adjective on it
     * — it is the thing the chapter is about. The engine has already made the
     * moment extraordinary in the world (ground torn open, a weapon gone out
     * of reach, a whole room turned); this tells the narrator to write it at
     * that scale, and fixes the boundary where scale stops and invention
     * would start. Returns '' when nothing critical happened, so an ordinary
     * turn never carries instructions to be enormous.
     */
    private function criticalMoments(Turn $turn): string
    {
        $resolution = $turn->resolution ?? [];

        $lines = collect($resolution['beats'] ?? [])
            ->reject(fn (array $beat) => ($beat['crit'] ?? null) === null || ($beat['skipped'] ?? false))
            ->map(fn (array $beat) => '- '.($beat['crit'] === 'success' ? 'CRITICAL SUCCESS' : 'CRITICAL FAILURE')
                .' on '.str_replace('_', ' ', $beat['verb'])
                .(($beat['target']['name'] ?? null) !== null ? " ({$beat['target']['name']})" : ''))
            ->merge(collect($resolution['reaction_rolls'] ?? [])
                ->reject(fn (array $roll) => ($roll['crit'] ?? null) === null)
                ->map(fn (array $roll) => '- '.($roll['crit'] === 'success' ? 'CRITICAL HIT' : 'CRITICAL MISS')
                    ." by {$roll['actor']}"))
            ->values();

        if ($lines->isEmpty()) {
            return '';
        }

        $listed = $lines->join("\n");

        return <<<CRIT

        ## The moment this chapter turns on
        {$listed}

        This vignette hit the extreme end of what could happen. That moment is the chapter's spine: give it the most room, the sharpest images and the strongest verbs on the page, and arrange everything else around it — what led into it, and what this place looks like once it has happened.

        - On a critical success, do not write a good version of an ordinary act. Write the version people still talk about afterwards. Where the facts say the ground was torn open, something enormous happened to the ground: decide what lies UNDER the ground in THIS land, and let it out. Where the facts say everyone heard it, turn the whole room.
        - On a critical failure, do not write a near miss. Write the version that goes wrong in a way nobody can take back in the same breath. Where the facts say a weapon left their hands, it does not clatter down at their feet — it is gone somewhere that will cost them to reach, and their hands should stay empty for the rest of the chapter.
        - Reach for the biggest image this land can actually carry, in this land's own materials. A torn floor is a different thing in a canopy town, a derelict station and an ash steppe — write the one this tale is standing in.
        - The facts remain fixed and complete. Scale is yours; outcomes are not. Do not kill, destroy, wound, gain, or lose anything the facts above do not state — you are deciding the SHAPE and SIZE of what is listed, never adding to the list.

        CRIT;
    }

    private function tone(): string
    {
        $biblePath = config('game.design_bible_path');
        $bible = File::exists($biblePath) ? File::get($biblePath) : '';

        // Tone, themes, and bounds only. Any place the bible names is an
        // illustration of voice — the tale's actual land arrives in the
        // prompt, campaign by campaign.
        return "Honor this design bible for tone, themes, and bounds only. Any specific place it names is an example of VOICE, never the setting of this tale — the campaign's own land is given in the prompt and outranks it:\n\n"
            .mb_substr($bible, 0, 4000);
    }
}
