<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EventWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly array $payload) {}

    public function via(mixed $notifiable = null): array
    {
        return ['database'];
    }

    public function toDatabase(mixed $notifiable = null): array
    {
        return $this->payload;
    }
}
