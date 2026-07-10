<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class DailyNudge extends Notification
{
    public function __construct(public readonly int $dueCount)
    {
    }

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $plural = $this->dueCount === 1 ? 'exercise' : 'exercises';

        return (new WebPushMessage)
            ->title('Exercise reminder')
            ->body("{$this->dueCount} {$plural} still due today.")
            ->icon('/icons/icon-192.png')
            ->tag('daily-nudge')
            ->data(['url' => route('today')]);
    }
}
