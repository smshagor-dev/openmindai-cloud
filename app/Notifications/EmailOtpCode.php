<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailOtpCode extends Notification
{
    public function __construct(public string $code, public string $purpose) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $registration = $this->purpose === 'registration';

        return (new MailMessage)
            ->subject($registration ? 'Verify your email · OpenMindAI' : 'Reset your password · OpenMindAI')
            ->view('mail.security-code', [
                'heading' => $registration ? 'Verify your email address' : 'Reset your password',
                'description' => $registration
                    ? 'Enter the following verification code to activate your account.'
                    : 'Enter the following code to securely reset your password.',
                'code' => $this->code,
            ]);
    }
}
