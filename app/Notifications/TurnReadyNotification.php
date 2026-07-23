<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Turn;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The loop's heartbeat: "your new situation is ready." A story beat,
 * never a system message.
 */
class TurnReadyNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Campaign $campaign,
        private readonly Chapter $chapter,
        private readonly ?Turn $nextTurn,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->campaign->character?->name.' — the story moved')
            ->body(Str::limit($this->chapter->intent_line ?? $this->chapter->plainBody(), 140))
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            ->tag('campaign-'.$this->campaign->id)
            ->data(['url' => route('play.show', $this->campaign)]);
    }
}
