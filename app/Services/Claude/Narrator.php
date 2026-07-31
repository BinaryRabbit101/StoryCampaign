<?php

namespace App\Services\Claude;

use App\Game\BranchTrigger;
use App\Game\Engine\Ambient;
use App\Game\Engine\ChapterEvents;
use App\Game\Engine\Clocks;
use App\Game\Engine\Companions;
use App\Game\Engine\Finale;
use App\Game\Engine\Grudges;
use App\Game\Engine\Hours;
use App\Game\Engine\Scars;
use App\Game\Engine\Standings;
use App\Game\Engine\Threads;
use App\Models\Chapter;
use App\Models\Turn;
use App\Notifications\TurnReadyNotification;
use App\Services\BookCompiler;
use App\Services\Echoes;
use App\Services\Mementos;
use App\Services\PlayerPresence;
use App\Services\Rumors;
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
        private readonly BookCompiler $books,
    ) {}

    /**
     * Write this turn's chapter, or stand down because somebody else already is.
     *
     * Null means the pen was already taken — never that anything failed. Two
     * paths reach here (the inline dispatch after the player's own response,
     * and the every-minute sweep), a Claude call runs well past a minute, and
     * `narrated_at` is not written until that call comes back. Without the
     * claim below both paths read an unnarrated turn and both write a chapter,
     * which is how five of nine turns in one playthrough got told twice.
     */
    public function narrate(Turn $turn): ?Chapter
    {
        if (! $this->claim($turn)) {
            return null;
        }

        $campaign = $turn->campaign;
        $character = $campaign->character;

        try {
            $response = $this->claude->promptForJson($this->buildPrompt($turn), $this->tone());
        } catch (\Throwable $e) {
            // The pen goes back on the table. A call that failed HERE is
            // retryable on the next sweep without waiting out the staleness
            // window — that window exists for the process that died still
            // holding the claim, which by definition cannot put it down.
            Turn::whereKey($turn->id)->whereNull('narrated_at')->update(['narration_claimed_at' => null]);

            throw $e;
        }

        $chapter = Chapter::create([
            'campaign_id' => $campaign->id,
            'turn_id' => $turn->id,
            'number' => $campaign->nextChapterNumber(),
            'kind' => 'chapter',
            'intent_line' => $response['intent_line'] ?? null,
            'body' => $response['chapter'] ?? throw new \RuntimeException('Narration missing chapter body.'),
        ]);

        $turn->update(['narrated_at' => now()]);

        // The chapter's line joins the running "story so far" — the memory
        // the next narration reads so promises and grudges outlive the
        // two-chapter window. A missing line costs only that chapter's entry.
        $campaign->appendSynopsis($chapter->number, $response['synopsis_line'] ?? null);

        // The keepsake this chapter leaves behind already exists — the engine
        // minted it during resolution, with its own words, and it stands on the
        // shelf whether this call ever happened. All that lands here is better
        // wording (clamped, and refused whole if it strays) and the stamp of
        // the chapter it belongs to, so the book can cite the page. A failure
        // costs the wording and nothing else.
        try {
            Mementos::reword($turn, $chapter, $response['memento'] ?? null);
        } catch (\Throwable $e) {
            report($e);
        }

        // The same bargain for the news this chapter carried: the line is
        // already true and already written, and all that lands here is better
        // wording inside a clamp, plus the stamp of the chapter that heard it.
        try {
            Rumors::reword($turn, $chapter, $response['rumor'] ?? null);
        } catch (\Throwable $e) {
            report($e);
        }

        // And the same bargain again for the memory a finished tale sent
        // across. Narrower than either of the two above: only the wrapper is
        // writable, because the words inside it are a quotation of something
        // the player actually lived and a reworded quotation is a fabrication.
        try {
            Echoes::reword($turn, $chapter, $response['echo'] ?? null);
        } catch (\Throwable $e) {
            report($e);
        }

        // The tale of someone who spent everything. The fall past the scar cap
        // ends the campaign — and it closes HERE, behind the chapter that tells
        // it, so the coda lands after the fall in the bound book rather than
        // before it. The sweep retries a narration that failed, which means the
        // close is retried with it and a broken Claude call cannot strand a
        // campaign half-ended.
        if ($turn->resolution['fall']['final'] ?? false) {
            try {
                $this->books->close($campaign->fresh(), early: true, withCoda: true);
            } catch (\Throwable $e) {
                report($e);
            }

            return $chapter;
        }

        // And the tale that reached the end it CHOSE. Same path and the same
        // reasoning: the coda belongs behind the chapter that tells the last of
        // it, never ahead of it. The only difference from the fall above is
        // which condition brought the tale here — everything after it is
        // identical, because the close is the existing close.
        if ($turn->resolution['finale']['complete'] ?? false) {
            try {
                $this->books->close($campaign->fresh(), early: true, withCoda: true);
            } catch (\Throwable $e) {
                report($e);
            }

            return $chapter;
        }

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

    /**
     * Take the pen, or find it already taken.
     *
     * ONE conditional UPDATE, because the whole point is that two processes
     * asking at the same instant must get two different answers: the database
     * decides, not a read-then-write in PHP. A turn is claimable when nobody
     * holds it, or when whoever held it has been holding it long enough
     * (`game.narration_claim_minutes`) to have died — and never once the
     * chapter exists, which is what makes `narrated_at` the real end of it.
     */
    private function claim(Turn $turn): bool
    {
        $stale = now()->subMinutes((int) config('game.narration_claim_minutes', 10));

        $won = Turn::whereKey($turn->id)
            ->whereNull('narrated_at')
            ->where(fn ($q) => $q->whereNull('narration_claimed_at')->orWhere('narration_claimed_at', '<=', $stale))
            ->update(['narration_claimed_at' => now()]);

        return $won === 1;
    }

    private function buildPrompt(Turn $turn): string
    {
        $campaign = $turn->campaign;
        $character = $campaign->character;
        $resolution = $turn->resolution;
        $submission = $turn->submission ?? [];

        $recent = $campaign->chapters()->reorder('number', 'desc')->limit(2)->get()->reverse();
        $previousChapters = $recent
            ->map(fn (Chapter $c) => '### '.($c->kind === 'prologue' ? 'Prologue' : "Chapter {$c->number}")
                ."\n".mb_substr($c->plainBody(), -1200))
            ->join("\n\n");

        // The first chapter is the one that used to read as a different book.
        // The prologue now ends standing in this exact scene, so this chapter
        // has to pick the same moment up rather than arrive at it again — no
        // second entrance, no re-describing ground the reader just walked in on.
        $continuation = $recent->contains(fn (Chapter $c) => $c->kind === 'prologue')
            ? "\n## This chapter continues directly from the prologue above\n"
                .'The prologue ends in the same place and the same moment this chapter begins — the reader has just finished it. '
                .'Pick the scene up mid-breath: no fresh arrival, no re-introducing the character, no describing this ground a second time. '
                ."Whoever the prologue named is still standing where it left them, and they keep the names it gave them.\n"
            : '';

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
        $register = ProseStyle::rules();

        // Who walked through with them, on the chapter where the ground
        // changed. Empty on every other chapter.
        $crossing = $this->crossingBlock($turn, $nextTurn);

        // Long-range memory: the one-line-per-chapter record kept by earlier
        // narrations. Without it, anything older than the two recent chapters
        // below has simply never happened as far as the narrator knows.
        // A returned grudge standing visible in the scene is a reunion, and
        // the narrator must know it: name, disposition, and the history —
        // already prose facts, never mechanics. Empty when no old score is
        // here, and a lurking return stays as hidden from this as from cards.
        $figures = Grudges::returningFigures($turn);

        // The multi-turn goal the player committed a beat to: finished this
        // chapter, or still being worked at. A plain goal in plain words and
        // never a count — the tally is the board's, and a tally on the page is
        // mechanics language wearing prose.
        $endeavor = Clocks::narratorBlock($turn);

        // Who is walking beside them, and what passed between them this time.
        // The tier arrives as plain words about behaviour — never as a number,
        // and never as a status the chapter is allowed to announce.
        $company = Companions::narratorBlock($turn);

        // How the ground itself holds them, and whether this chapter moved it.
        // Plain facts about doors, prices, and greetings — never a reputation
        // the chapter is allowed to announce, and empty on ground that has no
        // opinion of them, which is most of it.
        $standing = Standings::narratorBlock($turn);

        // Somebody else's small story, if the tale has met one. Plain facts
        // about what that person wants and how near they are to it — never a
        // count, and never a word until the player actually discovered it: an
        // undiscovered want carries no block at all, so the narrator cannot
        // write about a story nobody in the tale has heard.
        $sideStory = Threads::narratorBlock($turn);

        // The floor, when the character hit it. Where they went down, what
        // happened while they were out, where they came round, and the
        // permanent mark it left — plain facts, no mechanics, and the chapter's
        // whole subject when it is there at all. The standing marks block is
        // suppressed on that chapter: the fall block already names the fresh
        // one, and listing it twice invites the narrator to write it twice.
        $fall = Scars::narratorBlock($turn);
        $marks = $fall === '' ? Scars::marksBlock($character) : '';

        // The closing movement, while the tale is walking its last stretch —
        // and the facts of the ending on the chapter it lands in. Guidance
        // about SHAPE, in plain words: write toward rest, let it narrow, never
        // announce that a story is ending. Empty on every chapter of every tale
        // that has not taken its ending up.
        $closing = Finale::narratorBlock($turn);

        // The one thing this chapter left on the shelf, if it left anything.
        // The object is fixed and already written down; the invitation is only
        // to word it better than the engine could, inside a clamp. Empty on
        // every ordinary chapter, so nothing asks for a keepsake that is not there.
        $keepsake = Mementos::narratorBlock($turn);
        $mementoField = $keepsake === ''
            ? ''
            : ', "memento": {"name": "<the keepsake\'s name, at most '.Mementos::MAX_NAME_WORDS
                .' words>", "line": "<one plain sentence about it, at most '.Mementos::MAX_LINE_WORDS
                .' words — or repeat the given words exactly>"}';

        // News from elsewhere, if any reached them this chapter. True and
        // already written: the invitation is only to say it the way this land
        // would say it, inside a clamp. Empty on every ordinary chapter, so
        // nothing ever asks Claude to make up news that does not exist.
        $news = Rumors::narratorBlock($turn);
        $rumorField = $news === ''
            ? ''
            : ', "rumor": "<the same piece of news in this land\'s own words, one plain sentence of at most '
                .Rumors::MAX_LINE_WORDS.' words — or repeat the given words exactly>"';

        // A line out of one of this player's finished books, surfacing because
        // this moment rhymed with the moment that preserved it. The quoted
        // words are a quotation and stay one — the invitation is only to reword
        // the wrapper. Empty on every ordinary chapter, and empty forever for a
        // player with nothing behind them, so Claude is never asked to remember
        // on their behalf.
        $remembering = Echoes::narratorBlock($turn);
        $echoField = $remembering === ''
            ? ''
            : ', "echo": "<the same memory with the wrapper in this land\'s voice — at most '
                .Echoes::MAX_FRAME_WORDS.' words of wrapper, the quoted words and the tale\'s name exactly as given — or repeat the whole line exactly>"';

        // How the wait before this vignette was spent: one engine-written
        // sentence, plain and factual, carrying no numbers and no name for
        // what the player chose. It is a clause of colour at the open, not a
        // scene — a chapter that spends a paragraph on a night's sleep has
        // buried the beats it is actually about.
        $wait = trim((string) ($resolution['downtime'] ?? ''));
        $wait = $wait === '' ? '' : <<<WAIT

## How the stretch before this chapter passed (fixed fact)
{$wait}
Let it colour at most the opening clause of the chapter, in your own words. Never a paragraph of its own, and never the chapter's subject.

WAIT;

        // The air the chapter closes in — the same key the situation board
        // above is already saying abstractly, so the page and the chapter
        // cannot describe two different skies. The engine says WHAT it is; the
        // land says what it looks like here, and the land outranks every
        // example in the bible. One mention: a chapter that files a weather
        // report every few paragraphs has stopped being about its beats.
        $ambient = Ambient::fact(Ambient::of($nextTurn?->scene ?? $turn->scene));
        $air = $ambient === null ? '' : <<<AIR

        ## The air this scene stands in (fixed fact)
        {$ambient}
        Render it exactly ONCE, in whatever form this land takes it, and let it ride inside the action — a clause on the way through a beat, never a paragraph and never the opening line. Do not return to it afterwards. What it is, is fixed; what it looks like here belongs to the land above.

        AIR;

        // The light this chapter stands in — the same phase the board above is
        // already saying abstractly, so the page and the chapter cannot stand
        // at two different hours. The engine says WHERE ON THE WHEEL it is; the
        // land says what that looks like here, and the land outranks every
        // example in the bible: a derelict station has no sunrise, it has a
        // deck coming up out of its dimmed cycle.
        //
        // Never a clock-face time, ever. The engine does not know what o'clock
        // it is in this world and would be inventing one to say so. Plain day
        // carries no block at all — it is the baseline, and a chapter told to
        // mention that the light is ordinary has been given a chore.
        $phase = Hours::of($campaign);
        $light = Hours::fact($phase);
        $turned = trim((string) ($resolution['hour'] ?? ''));
        $turned = $turned === '' ? '' : "\n{$turned} Let the change land inside the action, once, as the beats run past it.";
        $hour = $light === null ? '' : <<<HOUR

        ## The light this scene stands in (fixed fact)
        {$light}{$turned}
        Render it exactly ONCE, in whatever form this land keeps time — a horizon, a shift change, a lamp cycle coming up or going down — and let it ride inside the action rather than opening the chapter. Never name a clock time or an hour of the day: what the light is doing is fixed, what it looks like here belongs to the land above.

        HOUR;

        // What this place did while nobody made it do anything. The character
        // held still, and the world took the initiative they declined — an
        // arrival, an accident, something the ground had been keeping back.
        // Fixed facts like any other, and they belong INSIDE the vignette: a
        // chapter that files them as a closing note has quietly told the player
        // their stillness cost nothing.
        $moves = collect($resolution['world'] ?? [])->map(fn (string $f) => "- {$f}")->join("\n");
        $world = $moves === '' ? '' : <<<WORLD

        ## What this place did while they held still (fixed facts)
        {$moves}
        Nobody made these happen. Write them as the scene moving on its own, once each, in this land's own terms, and let them land inside the action rather than as a closing note.

        WORLD;

        // The stop condition reaches the narrator as its plain-words
        // description, never the raw enum slug: handing Claude
        // "meaningful_fork" plus "decision point" language is how someone in
        // the scene ends up reading the fork aloud as a menu.
        $stopNote = BranchTrigger::tryFrom((string) $turn->branch_trigger)?->description()
            ?? 'The moment settles; time passes.';

        $synopsis = trim((string) $campaign->synopsis);
        $storySoFar = $synopsis === '' ? '' : <<<SOFAR

## The story so far (one line per chapter, oldest first — the names, promises, debts, and grudges this tale must stay true to)
{$synopsis}

SOFAR;

        return <<<PROMPT
You are the narrator of a living-world RPG. Write the next chapter of this campaign as flowing third-person past-tense prose, weaving the ENGINE-RESOLVED beats below into one continuous vignette. You decide how things happened, never whether: every fact listed is fixed. Do not mention dice, rolls, cards, slots, meters, or any mechanics.

{$register}

## Who may be in this chapter (standing rule)
Only the people the facts below say are present may appear, speak, or act. Anyone the facts do not list is somewhere else — they may be remembered, named, worried about or spoken of, and they may not be shown in the scene. The story-so-far and the recent chapters are MEMORY, never a roster: somebody who was standing beside the character two chapters ago is not standing there now unless the facts of this turn say so. If you find yourself needing a person the facts do not name, that is the scene telling you they are gone.

## Style: action over atmosphere
- The beats carry the chapter. Every paragraph must have something HAPPENING — movement, contact, exchange, consequence.
- Description rides inside the action: a clause on the way through a beat, never a standalone scene-painting paragraph. If a detail doesn't change what someone does or feels next, cut it.
- Prefer concrete verbs to stacked adjectives. Short sentences in the thick of it; longer ones only as the dust settles.
- Open inside motion or intent — never with the weather, the light, or the skyline.
- Match length to substance: few beats mean a short chapter. Never pad toward a word count — a tight half-page beats a stretched full one.

Each listed event carries a bracketed token like [[e1]]. Copy every token into the chapter VERBATIM, each exactly once, placed immediately after the sentence where that event lands in the prose. The tokens are invisible anchors in the reader's edition — never mention them, never describe them, never invent new ones.

## The land this tale walks (fixed — every name and thing belongs here)
{$land}

## Character
{$character->name}: {$character->description}
{$marks}
## The stage the player set (color and direction only — never force the goal closed; the player decides when it is met)
{$stage}

{$storySoFar}{$wait}{$air}{$hour}
## Recent chapters (for continuity)
{$previousChapters}
{$continuation}
{$intent}
## Voicing {$character->name} (the player's character)
Quoted dialogue for {$character->name} belongs to the PLAYER. Write speech in quotes for them ONLY inside a beat that carries the player's own words, and stay close to those words. On beats without them, {$character->name} acts but does not speechify: at most a short functional line the action itself demands ("Hold the door."), never invented sentences of dialogue. Everyone else speaks freely — the register asks for it.

## Engine-resolved beats of this vignette (fixed facts, in order)
Some beats carry the player's own words for that moment. Those words are voice and flavor: honor their spirit in how you tell the beat, and never let them alter what the beat's facts say happened.
{$beats}

## How the scene answered
{$reaction}
{$world}{$crossing}{$endeavor}{$figures}{$company}{$standing}{$sideStory}{$fall}{$closing}{$news}{$remembering}{$keepsake}{$criticals}
## Where the vignette stops
{$stopNote} The chapter must end on this note, mid-situation and unresolved. {$wordLow}-{$wordHigh} words.

## The state of play as the chapter closes (fixed facts)
{$aftermath}

Close the chapter inside this moment, with the people and surroundings named above present in the prose, so nothing the reader later acts on appears unannounced. Do not summarize or list them — let the scene hold them naturally. This binds dialogue too: no character may enumerate the ways forward, offer the reader a this-or-that, or ask which path they will take. End on the situation itself, not on a question about it.

Respond with ONLY a JSON object:
{"intent_line": "<the chapter's italic epigraph — the ONE place a turned phrase is welcome; a compact line in the spirit of 'She chose to take the rooftops.' or 'They read the shack before they read the woman.' The prose register's image limit does not bind this single line. Or null.>", "chapter": "<the chapter prose>", "synopsis_line": "<ONE factual line for the campaign's running record: what this chapter changed — names met, promises made, injuries taken, debts owed. Plain record-keeping, no style.>"{$mementoField}{$rumorField}{$echoField}}
PROMPT;
    }

    /**
     * Who came through the door, and who did not.
     *
     * The synopsis is memory, and memory is not a roster: handed a wounded
     * stranger from two chapters ago it will keep writing her into the scene,
     * while her actor row never left the ground she was met on. The board and
     * the cards cannot see her, so the player is reading a story they are not
     * allowed to act on — which is the worst kind of desync, because nothing
     * looks broken.
     *
     * So on the turn the ground changes, the facts say plainly who crossed and
     * name who stayed. Prompt-side only: nothing here moves an actor, and the
     * engine has already decided every word of it.
     */
    private function crossingBlock(Turn $turn, ?Turn $next): string
    {
        $before = $turn->scene;
        $after = $next?->scene;

        if ($before === null || $after === null || $before->id === $after->id) {
            return '';
        }

        // Exactly who the resolver moves at a transition: whoever walks beside
        // them, and whoever has taken to following without being asked.
        $crossed = Companions::beside($after)
            ->merge(Companions::strays($after))
            ->pluck('name')->unique()->values();

        // And whoever is still standing where they were met. Hidden stays
        // hidden here as everywhere: a lurker in the old scene is not a name
        // the chapter gets to mourn.
        $stayed = $before->visibleActors()
            ->filter(fn ($actor) => $actor->kind !== 'enemy')
            ->pluck('name')->unique()->values();

        $came = $crossed->isEmpty()
            ? 'Nobody crossed with them: they walked into this ground alone.'
            : 'Crossed with them, and standing here now: '.$crossed->join(', ').'.';

        $left = $stayed->isEmpty()
            ? 'Everyone met on the old ground stayed behind on it.'
            : 'Stayed behind on the old ground and is NOT here: '.$stayed->join(', ')
                .'. They may be remembered, missed, or spoken of — they may not appear, speak, or act in this chapter.';

        return <<<CROSSING

        ## Who came through with them (fixed facts — this chapter changed ground)
        {$came}
        {$left}

        CROSSING;
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
        - Reach for the biggest EVENT this land can actually carry, in this land's own materials. A torn floor is a different thing in a canopy town, a derelict station and an ash steppe — write the one this tale is standing in. Scale lives in what literally happens, not in fancier language: the prose register above still holds at full force.
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
