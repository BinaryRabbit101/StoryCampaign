<?php

namespace App\Http\Controllers;

use App\Game\Engine\ChapterEntities;
use App\Game\Engine\ChapterEvents;
use App\Game\Engine\Clocks;
use App\Game\Engine\Downtime;
use App\Game\Engine\RollTable;
use App\Game\Engine\Scars;
use App\Game\Engine\TurnResolver;
use App\Game\Hands;
use App\Game\TurnSlot;
use App\Game\Verb;
use App\Models\Campaign;
use App\Models\Scene;
use App\Models\Turn;
use App\Services\Claude\Narrator;
use App\Services\GrowthLedger;
use App\Services\Mementos;
use App\Services\PlayerPresence;
use App\Services\Recap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PlayController extends Controller
{
    public function show(Request $request, Campaign $campaign): Response|RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        if ($campaign->status !== 'active') {
            return redirect()->route('campaigns.show', $campaign);
        }

        $turn = $campaign->currentTurn;
        $character = $campaign->character->load(['capabilities', 'constraints', 'items']);
        $latestChapter = $campaign->chapters()->reorder('number', 'desc')->first();
        $chapterTurn = $latestChapter?->turn;

        // The player is here, on this page, right now — so the turn-ready push
        // has nothing to tell them. Marked before anything slow happens, since
        // the polling reload IS this request.
        PlayerPresence::mark($campaign);

        // Resolution is inline, so the only wait left is Claude writing the
        // chapter. That wait has to be visible: the turn behind it is already
        // complete, and without this the page would quietly re-show the
        // PREVIOUS chapter as though it were the new one.
        $unnarrated = $campaign->turns()
            ->whereNotNull('resolved_at')
            ->whereNull('narrated_at')
            ->orderByDesc('number')
            ->first();

        // ...but only while it is still plausibly happening. Past the window
        // the wait is a failure, and presenting a failure as progress cost a
        // player an entire evening staring at a breathing panel that was
        // never going to resolve — with the chapters they DID have hidden
        // behind it.
        $narrating = $unnarrated !== null && ! $unnarrated->narrationIsLate();
        $narrationStalled = $unnarrated !== null && $unnarrated->narrationIsLate();

        // Where they are standing, and the country they have already walked.
        // The map is a trail, not a promise: it draws only ground the tale has
        // actually touched, in the zone the player is currently in, plus the
        // unwalked ways out of the ground they stand on.
        $scene = $turn?->scene()->first() ?? $campaign->activeScene;
        $place = null;
        if ($scene !== null) {
            $visited = $scene->campaign_id === null ? collect() : Scene::query()
                ->where('campaign_id', $scene->campaign_id)
                ->where('zone_id', $scene->zone_id)
                ->orderBy('id')
                ->get(['id', 'title', 'status', 'from_scene_id', 'from_direction', 'grid_x', 'grid_y']);
            $place = [
                'zone' => $scene->zone->only(['name']),
                'scene' => ['id' => $scene->id, 'title' => $scene->title],
                'map' => $visited->map(fn ($s) => [
                    'id' => $s->id,
                    'title' => $s->title,
                    'x' => $s->grid_x,
                    'y' => $s->grid_y,
                    'from' => $s->from_scene_id,
                    'current' => $s->id === $scene->id,
                ])->values(),
                'exits' => $scene->exits()->whereNull('to_scene_id')->orderBy('id')
                    ->get()->map(fn ($e) => ['direction' => $e->direction, 'label' => $e->label])->values(),
            ];
        }

        return Inertia::render('Play', [
            'narrating' => $narrating,
            'narrationStalled' => $narrationStalled,
            'rollTable' => $this->pendingRollTable($campaign),
            'campaign' => $campaign->only(['id', 'name', 'status']),
            'place' => $place,
            'character' => [
                'name' => $character->name,
                'description' => $character->description,
                'status' => $character->status,
                'meters' => $character->meters,
                // What is physically in their hands right now — scene matter
                // they picked up, not the items they own.
                'carrying' => Hands::held($character),
                'hands_free' => Hands::free($character),
                'capabilities' => $character->capabilities->map(fn ($c) => $c->only(['capability', 'magnitude', 'grade', 'scope', 'source'])),
                'constraints' => $character->constraints->map(fn ($c) => $c->only(['name', 'params', 'coupled_capability'])),
                // What the tale has permanently taken. Surfaced plainly on the
                // strip rather than buried among the burdens: a mark that cost
                // a whole fall to acquire, and that quietly charges two points
                // on half the cards, has to be somewhere the player sees it.
                'scars' => Scars::marks($character),
                'items' => $character->items->map(fn ($i) => [
                    'name' => $i->name,
                    'description' => $i->description,
                    'power' => $i->power,
                    'grants' => $i->grants,
                    'equipped' => (bool) $i->pivot->equipped,
                    'charges' => $i->pivot->charges,
                ]),
            ],
            'turn' => $turn === null ? null : [
                'id' => $turn->id,
                'number' => $turn->number,
                'status' => $turn->status,
                'situation' => $turn->situation,
                // Grouped bullets, shown beside every chapter. Turns opened
                // before the board existed carry none; the page falls back to
                // the prose those turns were written with.
                'board' => $turn->situation_board,
                'cards' => $turn->isOpen() ? $this->withVocabulary($turn->cards) : null,
            ],
            // The endeavor under way, if the player took one on: the goal in
            // its own words and how far along it is. It stands beside the
            // meters because it is the one thing on this page that carries
            // across turns and has to be read before committing the next one.
            'endeavor' => Clocks::page($campaign),
            // The shelf: what this tale has left behind, in the order it
            // happened. Inert in every direction — it is here to be read, and
            // there is nothing on it to spend.
            'mementos' => Mementos::shelf($campaign),
            // Previously, on this tale. Null unless the player has genuinely
            // been away — compiled from lines the engine already wrote, and
            // dismissed on the client, so it costs this request one read and
            // never stands between anybody and the form.
            'recap' => Recap::for($campaign),
            // The evolution conversation, so a request and the world's answer
            // to it stay on screen together. Without this the answer was
            // written to the database and shown to nobody.
            'growth' => $campaign->interviewMessages()
                ->where('kind', 'growth')->orderBy('id')->get()->take(-10)
                ->map(fn ($m) => $m->only(['id', 'role', 'body', 'granted', 'changes', 'suggestions']))
                ->values(),
            // What the tale has paid out and what the sheet has spent of it.
            // Always present, always a number: a stated zero is information —
            // "nothing in hand yet" is why the world keeps saying not yet, and
            // a hidden panel would leave the player guessing at the reason.
            'growthLedger' => GrowthLedger::panel($character),
            'latestChapter' => $latestChapter === null ? null : [
                ...$latestChapter->only(['number', 'kind', 'intent_line', 'body']),
                'events' => $chapterTurn === null ? [] : ChapterEvents::for($chapterTurn),
                // The people and ground the prose can name — matched inside
                // the chapter text so the reader can touch them for detail.
                // A prologue or chronicle has no turn; it still stands
                // somewhere, so the active scene answers for it.
                'entities' => ChapterEntities::for($campaign, $chapterTurn),
            ],
        ]);
    }

    /**
     * The board word and the short name for every card on the turn.
     *
     * Cards composed before the catalog existed are stored without either, and
     * the board is a LENS: it may never drop a card the engine offered. So the
     * vocabulary is filled in here, from the catalog, rather than guessed at on
     * the client — which is the whole reason the family rides on the card in
     * the first place.
     *
     * @param  array<string,mixed>|null  $cards
     * @return array<string,mixed>|null
     */
    private function withVocabulary(?array $cards): ?array
    {
        if ($cards === null) {
            return null;
        }

        $fill = function (array $card): array {
            $card['family'] ??= Verb::familyOf($card['verb'] ?? '')->value;
            $card['verb_label'] ??= Verb::labelOf($card['verb'] ?? '');

            return $card;
        };

        foreach (TurnSlot::playerSlots() as $slot) {
            $cards[$slot->value] = array_map($fill, $cards[$slot->value] ?? []);
        }

        foreach ($cards['companions'] ?? [] as $index => $companion) {
            $cards['companions'][$index]['cards'] = array_map($fill, $companion['cards'] ?? []);
        }

        return $cards;
    }

    /**
     * The dice a resolved turn cast, if the player has not watched them fall
     * yet. Resolution and narration are untouched by this: the engine rolled
     * on its own schedule and Claude wrote on its own, so an idle player
     * still wakes to a finished chapter. The table only holds that chapter
     * back for as long as it takes to show the numbers behind it.
     */
    private function pendingRollTable(Campaign $campaign): ?array
    {
        $turn = $campaign->turns()
            ->whereNotNull('resolved_at')
            ->whereNull('rolls_seen_at')
            ->reorder('number', 'desc')
            ->first();

        if ($turn === null) {
            return null;
        }

        $rows = RollTable::for($turn);
        if ($rows === []) {
            // A turn of nothing but quiet beats casts no dice; there is no
            // table to show, so it never stands between player and chapter.
            $turn->update(['rolls_seen_at' => now()]);

            return null;
        }

        return [
            'turn_id' => $turn->id,
            'turn_number' => $turn->number,
            'rows' => $rows,
            // Something the character heard about somewhere else. It sits on
            // this screen with the same quiet weight as the wait below it —
            // no badge, no push, nothing to answer. The player finds it, the
            // way they find a keepsake.
            'heard' => $turn->resolution['rumor']['line'] ?? null,
            // And a line out of one of their own finished books, when this
            // moment rhymed with the moment that preserved it. Same weight as
            // the news above and for the same reason: it is memory, not a
            // result, and there is nothing on this screen to do about it.
            'remembered' => $turn->resolution['echo']['line'] ?? null,
        ];
    }

    /**
     * How the character spends the wait before this turn is played.
     *
     * A normal engine-offered choice: submitted by id and checked against the
     * closed set stored on the turn, exactly as a card is. It is recorded and
     * nothing else — the payout is the engine's, computed from the real
     * elapsed minutes at the top of the next resolution, so a pick can never
     * make the resolution itself wait on anything.
     *
     * NOTHING OFFERS THIS ANY MORE. The picker was removed from both screens:
     * the wait now passes the way it did before downtime existed, with tempo
     * regenerating and nothing else. The engine half is left standing (the
     * resolver still pays out a stance if one is somehow recorded, and the
     * offer is still written onto each turn), so the feature can be put back on
     * screen without being rebuilt — but no player-facing surface reaches here.
     */
    public function downtime(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'turn_id' => ['required', 'integer'],
            'stance' => ['required', 'string'],
            // What the player wants said at the fire. Same class as a beat
            // note: it colours the chapter and never reaches the arithmetic.
            'note' => ['nullable', 'string', 'max:280'],
        ]);

        $turn = $campaign->turns()->whereKey($validated['turn_id'])->first();
        abort_if($turn === null, 404);

        // The wait belongs to a turn not yet played. Once it is submitted the
        // stretch it offered has already been spent.
        abort_unless($turn->isOpen(), 409, 'That wait is already over.');

        // Once only: the clock starts at the pick, and re-picking would restart
        // it — an idle payout that can be re-armed is an idle payout that pays
        // whatever the player asks it to.
        abort_if(($turn->downtime['stance'] ?? null) !== null, 409, 'The wait is already spoken for.');

        if (! in_array($validated['stance'], Downtime::offeredStances($turn), true)) {
            throw ValidationException::withMessages([
                'stance' => 'That was not among the ways offered to spend the wait.',
            ]);
        }

        Downtime::choose($turn, $validated['stance'], note: $this->note($validated));

        return back();
    }

    /**
     * The player has watched the dice fall. Stamping the turn clears the
     * table for good — on this device and every other one.
     */
    public function rollsSeen(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'turn_id' => ['required', 'integer'],
        ]);

        $campaign->turns()
            ->whereKey($validated['turn_id'])
            ->whereNull('rolls_seen_at')
            ->update(['rolls_seen_at' => now()]);

        return back();
    }

    /**
     * The one structured form: pre/main/post card ids + modifiers, each with
     * an optional line in the player's own words. Resolves the moment it is
     * submitted — there is no waiting window between choosing and knowing.
     */
    public function submit(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
        abort_unless($campaign->status === 'active', 400);

        $turn = $campaign->currentTurn;
        abort_unless($turn !== null && $turn->isOpen(), 409, 'The form is locked until resolution completes.');

        $validated = $request->validate([
            'main' => ['required', 'array'],
            'main.card_id' => ['required', 'string'],
            'main.modifiers' => ['array'],
            'main.note' => ['nullable', 'string', 'max:280'],
            'pre' => ['nullable', 'array'],
            'pre.card_id' => ['required_with:pre', 'string'],
            'pre.modifiers' => ['array'],
            'pre.note' => ['nullable', 'string', 'max:280'],
            'post' => ['nullable', 'array'],
            'post.card_id' => ['required_with:post', 'string'],
            'post.modifiers' => ['array'],
            'post.note' => ['nullable', 'string', 'max:280'],
            'companions' => ['nullable', 'array'],
            'companions.*' => ['nullable', 'array'],
            'companions.*.card_id' => ['nullable', 'string'],
            'companions.*.note' => ['nullable', 'string', 'max:280'],
            'intent_text' => ['nullable', 'string', 'max:280'],
        ]);

        $submission = ['intent_text' => $validated['intent_text'] ?? null];

        foreach (TurnSlot::playerSlots() as $slot) {
            $choice = $validated[$slot->value] ?? null;
            if ($choice === null) {
                continue;
            }
            $submission[$slot->value] = [
                'card_id' => $choice['card_id'],
                'modifiers' => $this->validateChoice($turn, $slot, $choice),
                // Narration color for this one beat, stored beside the choice
                // it belongs to. It never reaches the mechanics path.
                'note' => $this->note($choice),
            ];
        }

        $companions = $this->validateCompanionChoices($turn, $validated['companions'] ?? []);
        if ($companions !== []) {
            $submission['companions'] = $companions;
        }

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => $submission,
            'submitted_at' => now(),
        ]);

        $this->resolveInline($turn);

        return redirect()->route('play.show', $campaign);
    }

    /**
     * A submitted card must be one the engine actually offered for that slot;
     * chosen modifier values must come from the card's own options.
     *
     * @return array<string, string>
     */
    private function validateChoice(Turn $turn, TurnSlot $slot, array $choice): array
    {
        $offered = collect($turn->cards[$slot->value] ?? [])->keyBy('id');
        $card = $offered->get($choice['card_id']);

        if ($card === null) {
            throw ValidationException::withMessages([
                $slot->value => 'That option was not among the cards offered.',
            ]);
        }

        $clean = [];
        foreach ($card['modifiers'] ?? [] as $modifier) {
            $chosen = $choice['modifiers'][$modifier['key']] ?? null;
            $valid = collect($modifier['options'])->pluck('value');
            $clean[$modifier['key']] = $valid->contains($chosen) ? $chosen : $valid->first();
        }

        return $clean;
    }

    /**
     * Companion requests are validated per companion: each key must be a
     * companion the engine listed on the turn, each card one offered for
     * that specific companion.
     *
     * @return array<string, array{card_id: string, modifiers: array}>
     */
    private function validateCompanionChoices(Turn $turn, array $choices): array
    {
        $offered = collect($turn->cards['companions'] ?? [])->keyBy('id');
        $clean = [];

        foreach ($choices as $companionId => $choice) {
            if ($choice === null || ($choice['card_id'] ?? null) === null) {
                continue;
            }

            $entry = $offered->get((int) $companionId);
            $card = $entry === null ? null : collect($entry['cards'])->firstWhere('id', $choice['card_id']);

            if ($card === null) {
                throw ValidationException::withMessages([
                    'companions' => 'That request was not among the ones offered.',
                ]);
            }

            $clean[(string) $entry['id']] = [
                'card_id' => $card['id'],
                'modifiers' => [],
                'note' => $this->note($choice),
            ];
        }

        return $clean;
    }

    /** The player's own words for one beat, trimmed to nothing-or-something. */
    private function note(array $choice): ?string
    {
        $note = trim((string) ($choice['note'] ?? ''));

        return $note === '' ? null : $note;
    }

    /**
     * Resolve now, write shortly.
     *
     * The engine's half is pure database work and finishes in milliseconds,
     * so it runs inside the request: by the time the player lands back on the
     * page their dice are already cast and waiting on the table. Claude's half
     * is the slow one, and it is deferred until after the response has been
     * flushed — which means the player spends that time rolling dice instead
     * of watching a spinner. Neither failure is fatal: a resolution that
     * throws leaves the turn locked for the sweep to recover, and a narration
     * that throws leaves a resolved turn the sweep will write within the
     * minute.
     */
    private function resolveInline(Turn $turn): void
    {
        try {
            app(TurnResolver::class)->resolve($turn);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $turnId = $turn->id;
        dispatch(function () use ($turnId) {
            $fresh = Turn::find($turnId);
            if ($fresh === null || $fresh->narrated_at !== null) {
                return; // the sweep got there first
            }
            try {
                app(Narrator::class)->narrate($fresh);
            } catch (Throwable $e) {
                report($e);
            }
        })->afterResponse();
    }
}
