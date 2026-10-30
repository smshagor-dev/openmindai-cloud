<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginIpVerification extends Notification
{
    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New sign-in verification · OpenMindAI')
            ->view('mail.security-code', [
                'heading' => 'Verify your sign-in',
                'description' => 'We noticed a sign-in attempt from a new network. Enter this code to continue.',
                'code' => $this->code,
            ]);
    }
}
