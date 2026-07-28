<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Turn;
use Illuminate\Support\Str;

/**
 * Previously, on this tale.
 *
 * The game is built for absence — turns wait, downtime pays, evolution tends,
 * rumors queue — but re-entry was cold. A player back after a night away landed
 * on the form and had to reconstruct the stakes from the board alone. A serial
 * does not do that to its audience: it says what happened last, in four lines,
 * and then gets on with the episode.
 *
 * Two disciplines hold this up, and they are the same two that hold up the book.
 *
 * 1. It is COMPILED, never generated. There is no Claude call on this path and
 *    there never may be — every line below is a string the engine already wrote
 *    and already clamped: a chapter's own epigraph, a fact from the last
 *    resolution, the downtime sentence, a keepsake's line, a group the situation
 *    board is already showing. Nothing here summarizes, paraphrases, or invents.
 *    A missing piece makes the panel shorter and nothing else; an empty section
 *    is simply absent, the same rule the board lives by.
 *
 * 2. It never gates play. The panel is informational and dismissible, it is
 *    absent below the absence threshold, and it renders above the form rather
 *    than in front of it. A player who never reads one loses nothing at all.
 *
 * Absence is read from timestamps that already existed — the last resolution's
 * own clock. No activity column, no beacon, no write of any kind: this service
 * reads models and returns an array.
 */
class Recap
{
    /**
     * The panel for this campaign, or null when there is nothing to say or
     * nobody has been away long enough to need it said.
     *
     * @return array{turn_id:int, sections:list<array{key:string,title:string,lines:list<string>}>}|null
     */
    public static function for(Campaign $campaign): ?array
    {
        $open = $campaign->currentTurn;

        // A turn already committed is not a re-entry: the player is here, and
        // whatever they missed they are about to be told by the chapter.
        if ($open === null || ! $open->isOpen()) {
            return null;
        }

        $last = $campaign->turns()
            ->whereNotNull('resolved_at')
            ->whereNotNull('resolution')
            ->reorder('number', 'desc')
            ->first();

        // A brand-new tale on its first turn has no previously. Nothing has
        // happened yet, and a recap of nothing is a panel apologising for
        // itself.
        if ($last === null || $last->resolved_at === null) {
            return null;
        }

        $hours = (int) config('game.recap.absence_hours', 12);

        if ($last->resolved_at->copy()->addHours($hours)->isFuture()) {
            return null;
        }

        $sections = array_values(array_filter([
            self::standing($campaign),
            self::happened($last),
            self::away($last),
            self::standingOpen($open),
        ], fn (array $section) => $section['lines'] !== []));

        return $sections === [] ? null : [
            // Dismissal is per open turn and lives on the client. The server
            // keeps no memory of it, which is exactly why the panel re-offers
            // itself on its own the next time somebody is away this long.
            'turn_id' => $open->id,
            'sections' => $sections,
        ];
    }

    /**
     * Where the tale stands: how many pages there are, and what the last one
     * was called. The chronicle is skipped for the second line on purpose —
     * it is the world's page rather than the tale's, and it has a line of its
     * own further down.
     */
    private static function standing(Campaign $campaign): array
    {
        $count = $campaign->chapters()->count();
        $lines = [];

        if ($count > 0) {
            $name = trim((string) ($campaign->title ?: $campaign->name));
            $lines[] = "{$name} — {$count} ".Str::plural('chapter', $count).' so far.';
        }

        $chapter = $campaign->chapters()
            ->where('kind', '!=', 'chronicle')
            ->reorder('number', 'desc')
            ->first();

        if ($chapter !== null) {
            $epigraph = trim((string) $chapter->intent_line);
            $lines[] = "Chapter {$chapter->number}".($epigraph === '' ? '' : " — {$epigraph}");
        }

        return self::section('standing', 'Where the tale stands', $lines);
    }

    /**
     * What just happened: fact strings off the last resolution, exactly as the
     * engine wrote them.
     *
     * The selection is favour-by-kind and then recency, and it is arithmetic —
     * no dice anywhere near it. A turn re-read a second time shows the same
     * four lines it showed the first time, which is the whole reason a player
     * can trust the panel enough to skim it.
     *
     * The kinds are ordered by what a returning player would be sorriest to
     * have forgotten: the fall first, then what the world did on its own, the
     * endeavor coming good, whoever walks beside them, the keepsake, what the
     * scene did back — and last their own beats, most recent first, because
     * within one turn the final beat is the one the next turn stands on.
     */
    private static function happened(Turn $last): array
    {
        $resolution = $last->resolution ?? [];

        $beats = [];
        foreach (array_reverse($resolution['beats'] ?? []) as $beat) {
            if ($beat['skipped'] ?? false) {
                continue;
            }
            foreach ($beat['facts'] ?? [] as $fact) {
                $beats[] = $fact;
            }
        }

        // The keepsake's own line — written by the engine the instant the turn
        // resolved, and reworded only inside the shelf's clamp. Read through
        // the service that owns it; the panel never touches the model.
        $memento = Mementos::forTurn($last);

        $lines = array_merge(
            self::strings($resolution['fall']['facts'] ?? []),
            self::strings($resolution['world'] ?? []),
            self::strings($resolution['endeavor'] ?? []),
            self::companionFacts($resolution['companions'] ?? []),
            $memento === null ? [] : self::strings([$memento->line]),
            self::strings($resolution['scene_reaction'] ?? []),
            self::strings($beats),
        );

        $lines = array_slice(array_values(array_unique($lines)), 0,
            max(0, (int) config('game.recap.fact_lines', 4)));

        return self::section('happened', 'What just happened', $lines);
    }

    /**
     * The wait itself: how it passed, whether the world was tended while it
     * did, and anything the character heard about somewhere else. All three
     * are already sentences — the panel only decides they belong together.
     */
    private static function away(Turn $last): array
    {
        $resolution = $last->resolution ?? [];
        $lines = self::strings([$resolution['downtime'] ?? null]);

        // The chronicle is cited, never quoted: a page of the book belongs in
        // the book, and a recap that reprinted it would be spending the thing
        // it is pointing at.
        $chronicle = Chapter::where('campaign_id', $last->campaign_id)
            ->where('kind', 'chronicle')
            ->where('created_at', '>=', $last->resolved_at)
            ->orderByDesc('number')
            ->first();

        if ($chronicle !== null) {
            $lines[] = "The world was tended while you were gone — a new chronicle stands at chapter {$chronicle->number}.";
        }

        return self::section('away', 'While you were away',
            array_merge($lines, self::strings([$resolution['rumor']['line'] ?? null])));
    }

    /**
     * What stands open, taken verbatim off the board the open turn is already
     * carrying. Not recomputed here: the board is the one place the state of
     * play is written down, and a second reading of it is a second answer
     * waiting to disagree with the first.
     */
    private static function standingOpen(Turn $open): array
    {
        $groups = collect($open->situation_board ?? [])->keyBy('key');

        $lines = array_merge(
            self::strings($groups['endeavor']['items'] ?? []),
            self::strings($groups['grudge']['items'] ?? []),
        );

        return self::section('open', 'What stands open', $lines);
    }

    /**
     * The companion map, flattened in its own order. Two of its keys carry a
     * record rather than a list; both keep their sentence under `fact`.
     *
     * @param  array<string,mixed>  $events
     * @return list<string>
     */
    private static function companionFacts(array $events): array
    {
        return array_merge(
            self::strings([$events['campfire']['fact'] ?? null]),
            self::strings([$events['interception']['fact'] ?? null]),
            self::strings($events['joined'] ?? []),
            self::strings($events['parted'] ?? []),
            self::strings($events['loss'] ?? []),
        );
    }

    /**
     * Plain sentences only. Anything that is not a non-empty string was never
     * written by the engine as a line, and the panel prints nothing it was not
     * handed in words.
     *
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private static function strings(iterable $values): array
    {
        $lines = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @return array{key:string,title:string,lines:list<string>}
     */
    private static function section(string $key, string $title, array $lines): array
    {
        return ['key' => $key, 'title' => $title, 'lines' => array_values($lines)];
    }
}
