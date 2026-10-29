<?php

namespace App\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupFailedNotification extends Notification
{
    public function __construct(public string $stage) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['message' => 'Automatic backup needs attention: '.$this->stage.'. Check the private backup log and docs/BACKUPS.md.',
            'type' => 'backup_failure', 'stage' => $this->stage, 'occurred_at' => now()->toIso8601String()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->error()->subject(config('app.name').' backup needs attention')
            ->line($this->toArray($notifiable)['message'])
            ->line('Previously verified Drive backups have been preserved.');
    }
}
