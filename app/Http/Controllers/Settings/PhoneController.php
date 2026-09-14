<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Phone: the one key the iPhone carries for the Scriptable status
 * widget. Its own page rather than a field on the profile form because the
 * buttons here have a side effect (the widget stops updating) that a "Save"
 * press must never carry by accident. Same shape as Budget's / Reminders'.
 */
class PhoneController extends Controller
{
    public function edit(Request $request): Response
    {
        $token = $request->user()->widget_token;

        return Inertia::render('settings/Phone', [
            'phone' => [
                'token' => $token,
                // The feed link carries the key so it pastes straight into
                // the widget's CONFIG; none until a key exists.
                'feeds' => $token === null ? [] : [
                    ['label' => 'Widget feed', 'url' => route('api.widget.status', ['token' => $token])],
                ],
            ],
        ]);
    }

    /** Mint (or roll) the key. Rolling revokes the old one immediately. */
    public function regenerate(Request $request): RedirectResponse
    {
        $existed = $request->user()->widget_token !== null;

        $request->user()->regenerateWidgetToken();

        Inertia::flash('toast', ['type' => 'success', 'message' => $existed
            ? __('New key generated. Paste it into the widget — the old one no longer works.')
            : __('Phone key generated.'),
        ]);

        return to_route('phone.edit');
    }

    public function revoke(Request $request): RedirectResponse
    {
        $request->user()->revokeWidgetToken();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Phone key revoked. The widget will stop updating.')]);

        return to_route('phone.edit');
    }
}
