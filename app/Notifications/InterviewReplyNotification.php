<?php

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The narrator answered during character creation.
 *
 * Only sent when the answer took long enough that the player may well have
 * put the phone down — a push for a reply that landed in three seconds is
 * noise, and the page already shows it.
 */
class InterviewReplyNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Campaign $campaign,
        private readonly string $reply,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('The narrator answered')
            ->body(Str::limit($this->reply, 140))
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            // Same tag as the campaign's other pushes: a replaced notification
            // rather than a growing stack of them.
            ->tag('campaign-'.$this->campaign->id)
            ->data(['url' => route('interview.show', $this->campaign)]);
    }
}
