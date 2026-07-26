<?php

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The character was born and the tale opened.
 *
 * Beginning runs three Claude calls back to back — the world forge, the
 * opening stage, the prologue — so it is the longest wait in the game and
 * the easiest one to walk away from. This is what tells the player it
 * finished, whether or not the tab that started it is still open.
 */
class StoryBegunNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Campaign $campaign) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $name = $this->campaign->character?->name ?? 'Your character';

        return (new WebPushMessage)
            ->title("{$name} stepped into the world")
            ->body("\"{$this->campaign->name}\" has begun. The first page is waiting.")
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            ->tag('campaign-'.$this->campaign->id)
            ->data(['url' => route('play.show', $this->campaign)]);
    }
}
