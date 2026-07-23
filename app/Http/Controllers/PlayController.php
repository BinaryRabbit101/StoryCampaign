<?php

namespace App\Http\Controllers;

use App\Game\Engine\ChapterEvents;
use App\Game\Engine\TurnResolver;
use App\Game\TurnSlot;
use App\Models\Campaign;
use App\Models\Turn;
use App\Services\Claude\Narrator;
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

        $resolvesAt = $turn?->status === Turn::STATUS_LOCKED && $turn->submitted_at !== null
            ? $turn->submitted_at->addMinutes((int) config('game.turn_cadence_minutes'))->toIso8601String()
            : null;

        return Inertia::render('Play', [
            'campaign' => $campaign->only(['id', 'name', 'status']),
            'character' => [
                'name' => $character->name,
                'description' => $character->description,
                'status' => $character->status,
                'meters' => $character->meters,
                'capabilities' => $character->capabilities->map(fn ($c) => $c->only(['capability', 'magnitude', 'grade', 'scope', 'source'])),
                'constraints' => $character->constraints->map(fn ($c) => $c->only(['name', 'params', 'coupled_capability'])),
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
                'cards' => $turn->isOpen() ? $turn->cards : null,
                'resolves_at' => $resolvesAt,
            ],
            'latestChapter' => $latestChapter === null ? null : [
                ...$latestChapter->only(['number', 'kind', 'intent_line', 'body']),
                'events' => $chapterTurn === null ? [] : ChapterEvents::for($chapterTurn),
            ],
        ]);
    }

    /**
     * The one structured form: pre/main/post card ids + modifiers + optional
     * intent line. Locks on submit — no second action until resolution.
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
            'pre' => ['nullable', 'array'],
            'pre.card_id' => ['required_with:pre', 'string'],
            'pre.modifiers' => ['array'],
            'post' => ['nullable', 'array'],
            'post.card_id' => ['required_with:post', 'string'],
            'post.modifiers' => ['array'],
            'intent_text' => ['nullable', 'string', 'max:280'],
        ]);

        $submission = ['intent_text' => $validated['intent_text'] ?? null];

        foreach (TurnSlot::cases() as $slot) {
            $choice = $validated[$slot->value] ?? null;
            if ($choice === null) {
                continue;
            }
            $submission[$slot->value] = [
                'card_id' => $choice['card_id'],
                'modifiers' => $this->validateChoice($turn, $slot, $choice),
            ];
        }

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => $submission,
            'submitted_at' => now(),
        ]);

        // Development convenience: with cadence 0 the turn resolves inline.
        if ((int) config('game.turn_cadence_minutes') === 0) {
            $this->resolveInline($turn);
        }

        return redirect()->route('play.show', $campaign);
    }

    /**
     * The impatience valve: resolve a committed turn on demand instead of
     * waiting out the cadence window. Only a locked turn with a stored
     * submission qualifies — an open form has nothing to resolve, so no
     * Claude run can ever fire without a player choice behind it.
     */
    public function resolveNow(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
        abort_unless($campaign->status === 'active', 400);

        $turn = $campaign->currentTurn;
        abort_unless(
            $turn !== null && $turn->status === Turn::STATUS_LOCKED && $turn->submitted_at !== null,
            409,
            'There is no committed turn waiting to resolve.',
        );

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

    private function resolveInline(Turn $turn): void
    {
        try {
            app(TurnResolver::class)->resolve($turn);
            app(Narrator::class)->narrate($turn->fresh());
        } catch (Throwable $e) {
            report($e); // the sweep will retry narration; resolution state is safe
        }
    }
}
