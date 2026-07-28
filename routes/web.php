<?php

use App\Http\Controllers\Api\WidgetController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\PlayController;
use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Token-authed widget snapshot (Scriptable pulls this JSON).
Route::get('api/widget/status', [WidgetController::class, 'status'])->name('api.widget.status');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/campaigns')->name('dashboard');

    Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::post('campaigns/{campaign}/end', [CampaignController::class, 'end'])->name('campaigns.end');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');

    Route::get('campaigns/{campaign}/interview', [InterviewController::class, 'show'])->name('interview.show');
    Route::post('campaigns/{campaign}/interview', [InterviewController::class, 'message'])->name('interview.message');
    Route::post('campaigns/{campaign}/interview/build', [InterviewController::class, 'build'])->name('interview.build');
    Route::post('campaigns/{campaign}/interview/begin', [InterviewController::class, 'begin'])->name('interview.begin');
    Route::get('campaigns/{campaign}/interview/status', [InterviewController::class, 'status'])->name('interview.status');
    Route::post('campaigns/{campaign}/grow', [InterviewController::class, 'grow'])->name('interview.grow');

    Route::get('play/{campaign}', [PlayController::class, 'show'])->name('play.show');
    Route::post('play/{campaign}', [PlayController::class, 'submit'])->name('play.submit');
    Route::post('play/{campaign}/rolls-seen', [PlayController::class, 'rollsSeen'])->name('play.rolls-seen');
    Route::post('play/{campaign}/downtime', [PlayController::class, 'downtime'])->name('play.downtime');

    Route::get('campaigns/{campaign}/book', [BookController::class, 'show'])->name('book.show');
    Route::get('campaigns/{campaign}/book/download', [BookController::class, 'download'])->name('book.download');

    Route::post('push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.store');
    Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');

    Route::post('widget/token', function (Request $request) {
        return response()->json(['token' => $request->user()->ensureWidgetToken()]);
    })->name('widget.token');
});

require __DIR__.'/settings.php';
