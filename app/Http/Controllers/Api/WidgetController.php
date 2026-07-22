<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Token-authed status endpoint for the Scriptable iPhone widget. Treat the
 * widget as "recent snapshot + tap to open" — iOS controls refresh timing.
 */
class WidgetController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $token = (string) $request->query('token', '');
        $user = $token === '' ? null : User::where('widget_token', $token)->first();

        abort_if($user === null, 401);

        $campaign = $user->campaigns()->where('status', 'active')->latest()->first();

        if ($campaign === null) {
            return response()->json(['active' => false]);
        }

        $turn = $campaign->currentTurn;
        $character = $campaign->character;
        $health = $character?->meters['health'];

        $resolvesAt = $turn?->status === Turn::STATUS_LOCKED && $turn->submitted_at !== null
            ? $turn->submitted_at->addMinutes((int) config('game.turn_cadence_minutes'))->toIso8601String()
            : null;

        return response()->json([
            'active' => true,
            'campaign' => $campaign->name,
            'character' => $character?->name,
            'health' => $health,
            'situation' => Str::limit($turn?->situation ?? '', 160),
            'awaiting_player' => $turn?->isOpen() ?? false,
            'resolves_at' => $resolvesAt,
            'chapter_count' => $campaign->chapters()->count(),
            'open_url' => route('play.show', $campaign),
        ]);
    }
}
