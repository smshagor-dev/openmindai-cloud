<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class SmtpTestMessage extends Mailable
{
    public function build(): static
    {
        return $this->subject('OpenMindAI SMTP test')->text('mail.smtp-test');
    }
}
