<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChangeEmail extends Notification
{
    public function __construct(public string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Confirm your OpenMindAI email change')->line('Confirm this address to change your account email. Your existing email stays active until you confirm.')->action('Review email change', $this->url)->line('This link expires in one hour. If you did not request this change, ignore this email.');
    }
}
