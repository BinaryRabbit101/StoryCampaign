<?php

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** World-evolution push: a story beat, not a patch note. */
class ChronicleNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Campaign $campaign,
        private readonly string $chronicle,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('The world changed overnight')
            ->body(Str::limit($this->chronicle, 140))
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            ->tag('chronicle')
            ->data(['url' => route('play.show', $this->campaign)]);
    }
}
