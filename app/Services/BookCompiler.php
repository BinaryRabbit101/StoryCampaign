<?php

namespace App\Services;

use App\Game\Engine\Scars;
use App\Models\Campaign;
use App\Models\Turn;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ProseStyle;
use Illuminate\Support\Str;

/**
 * End-of-campaign book: a compilation, not a generation. The chapters
 * already exist in the narration store; this assembles them (prologue,
 * chapters, witnessed chronicle entries, optional coda) and, at close,
 * asks Claude only for the optional coda + title/back-cover flourish.
 */
class BookCompiler
{
    public function __construct(private readonly ClaudeCli $claude) {}

    /** @return array{title:string, back_cover:?string, chapters:list<array>} */
    public function compile(Campaign $campaign): array
    {
        $chapters = $campaign->chapters()->get()
            ->map(fn ($chapter) => [
                'number' => $chapter->number,
                'kind' => $chapter->kind,
                'intent_line' => $chapter->intent_line,
                'body' => $chapter->plainBody(),
            ])->values()->all();

        return [
            'title' => $campaign->title ?? 'Chronicle of '.($campaign->character?->name ?? $campaign->name),
            'back_cover' => $campaign->back_cover,
            'started_at' => $campaign->started_at?->toFormattedDateString(),
            'ended_at' => $campaign->ended_at?->toFormattedDateString(),
            'ended_early' => $campaign->ended_early,
            'character' => $campaign->character?->name,
            'chapters' => $chapters,
            // What you carried home: the shelf, in chapter order, each with
            // the chapter that tells it. Compilation like everything else here
            // — the words were written when the moment happened. An empty
            // shelf is an empty list, and the book draws no section at all.
            'mementos' => Mementos::shelf($campaign),
        ];
    }

    /**
     * Close a campaign. On early exit the player chooses: a closing coda
     * chapter, or leave the story where it lies.
     */
    public function close(Campaign $campaign, bool $early, bool $withCoda): void
    {
        if ($early && $withCoda) {
            $lastChapters = $campaign->chapters()->reorder('number', 'desc')->limit(2)->get()->reverse()
                ->map(fn ($c) => mb_substr($c->plainBody(), -1500))->join("\n\n");
            $name = $campaign->character?->name ?? 'the wanderer';

            $stage = $campaign->stageBrief();
            $register = ProseStyle::rules();

            // What the tale took out of them, permanently. A closing page that
            // does not know the body it is closing over is writing about
            // somebody else — and on the fall that ends a campaign, the marks
            // ARE the arc: this is the tale of someone who spent everything.
            $scars = Scars::promptList($campaign->character);
            $scars = $scars === '' ? '' : <<<SCARS

            What this tale left permanently on them (fixed facts — let the body show where it has been; never list them):
            {$scars}

            SCARS;

            // And what they are taking with them. Objects, not achievements:
            // the closing page is allowed to let one of them show up in a
            // pocket, and is never allowed to read the list out.
            $carried = Mementos::promptList($campaign);
            $carried = $carried === '' ? '' : <<<CARRIED

            What they carry home from this tale (fixed objects — you may let one appear in the closing; never list them):
            {$carried}

            CARRIED;

            $coda = $this->claude->prompt(<<<PROMPT
The player is ending this RPG campaign early. Write a brief closing coda — a short epilogue (100-200 words, third-person past tense, no mechanics) that gracefully lands the story wherever the character was, in the spirit of: "And so her tale, for now, went quiet on the rooftops of the old district…"

{$register}

Character: {$name}
The stage the player set for this tale (acknowledge how far the goal got, resolved or not):
{$stage}
{$scars}{$carried}
Final chapters:
{$lastChapters}

Respond with ONLY the coda prose.
PROMPT);

            $campaign->chapters()->create([
                'turn_id' => null,
                'number' => $campaign->nextChapterNumber(),
                'kind' => 'coda',
                'intent_line' => null,
                'body' => $coda,
            ]);
        }

        $flourish = $this->titleFlourish($campaign);

        $campaign->update([
            'status' => 'completed',
            'ended_early' => $early,
            'ended_at' => now(),
            'title' => $flourish['title'] ?? null,
            'back_cover' => $flourish['back_cover'] ?? null,
        ]);

        $campaign->turns()->where('status', Turn::STATUS_AWAITING)
            ->update(['status' => Turn::STATUS_ABORTED]);
    }

    private function titleFlourish(Campaign $campaign): array
    {
        try {
            $opening = Str::limit($campaign->chapters()->first()?->plainBody() ?? '', 800);
            $closing = Str::limit($campaign->chapters()->reorder('number', 'desc')->first()?->plainBody() ?? '', 800);
            $name = $campaign->character?->name ?? $campaign->name;
            $stage = $campaign->stageBrief() ?: '(none set)';

            return $this->claude->promptForJson(<<<PROMPT
A campaign of a living-world RPG has ended and its chapters are being bound into a keepsake book. Give it a title and a one-line back-cover summary capturing the arc of THIS specific journey.

Character: {$name}
The stage the player set: {$stage}
How it opened: {$opening}
How it closed: {$closing}

Respond with ONLY a JSON object: {"title": "...", "back_cover": "..."}
PROMPT);
        } catch (\Throwable) {
            return []; // the flourish is optional; the book must still bind
        }
    }
}
