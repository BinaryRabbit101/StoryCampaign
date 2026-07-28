<?php

namespace App\Http\Controllers\Api;

use App\Game\Engine\Downtime;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mementos;
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
        $scene = $campaign->activeScene;

        // Turns resolve the instant they are submitted, so the only thing the
        // widget can ever be waiting on is Claude finishing the chapter.
        $narrating = $campaign->turns()
            ->whereNotNull('resolved_at')
            ->whereNull('narrated_at')
            ->exists();

        return response()->json([
            'active' => true,
            'campaign' => $campaign->name,
            'character' => $character?->name,
            'health' => $health,
            'tempo' => $character?->meters['tempo'] ?? [],
            // The widget draws a real health bar; the meter sentence is noise.
            'situation' => Str::limit(trim(preg_replace('/\s*Health \d+\/\d+\./', '', $turn?->situation ?? '')), 220),
            'awaiting_player' => $turn?->isOpen() ?? false,
            // How the character is spending the wait, if the player said.
            // Flavor only — the widget never offers the choice, it reports it.
            'downtime' => $turn?->isOpen() && ($turn->downtime['stance'] ?? null) !== null
                ? Downtime::flavor($turn->downtime['stance'])
                : null,
            'narrating' => $narrating,
            // The newest thing on the shelf, by name. Flavor only, like the
            // downtime line: the widget reports what the tale is carrying,
            // and there is nothing to do with it here or anywhere.
            'memento' => Mementos::latestName($campaign),
            'turn_number' => $turn?->number,
            'chapter_count' => $campaign->chapters()->count(),
            'companions' => $scene?->actors()
                ->where('status', 'active')->where('kind', 'companion')
                ->pluck('name') ?? [],
            'open_url' => route('play.show', $campaign),
        ]);
    }
}
