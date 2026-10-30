<?php

namespace App\Services;

use App\Models\SmtpSetting;

class SmtpSettings
{
    /**
     * This runs only when Laravel first resolves the Mail manager.
     * Avoids touching the database during migrations and ordinary requests.
     */
    public function apply(): void
    {
        $settings = SmtpSetting::query()->find(1);

        if (! $settings?->enabled) {
            return;
        }

        config()->set([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => $settings->encryption === 'ssl' ? 'smtps' : 'smtp',
                'host' => $settings->host,
                'port' => $settings->port,
                'username' => $settings->username ?: null,
                'password' => $settings->password ?: null,
                'timeout' => 15,
                'auto_tls' => $settings->encryption !== 'none',
                'require_tls' => $settings->encryption === 'tls',
            ],
            'mail.from' => [
                'address' => $settings->from_address,
                'name' => 'OpenMindAI',
            ],
            'mail.reply_to' => $settings->reply_to ? [
                'address' => $settings->reply_to,
                'name' => $settings->from_name,
            ] : null,
        ]);
    }
}
