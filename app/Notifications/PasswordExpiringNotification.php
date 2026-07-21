<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Password-rotation notice (docs/specs/04, 10 §5). */
class PasswordExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $daysRemaining,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line($this->body())
            ->action('Update password', url('/profile'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->daysRemaining <= 0 ? 'warning' : 'system',
            'title' => $this->subject(),
            'message' => $this->body(),
        ];
    }

    private function subject(): string
    {
        return $this->daysRemaining <= 0 ? 'Your password has expired' : 'Your password expires soon';
    }

    private function body(): string
    {
        $days = config('compliance.password_rotation_days');

        return $this->daysRemaining <= 0
            ? "Passwords must be changed every {$days} days. Update yours now to keep access."
            : "Your password expires in {$this->daysRemaining} day(s). Passwords must be changed every {$days} days.";
    }
}
