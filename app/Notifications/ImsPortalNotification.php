<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A line in the investor portal's notification list (IMS-V2/PRD.md FR-60),
 * stored in Laravel's `notifications` table for the portal account. Always
 * sent, whatever the SMS / e-mail choice.
 */
class ImsPortalNotification extends Notification
{
    public function __construct(
        public string $event,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
        ];
    }
}
