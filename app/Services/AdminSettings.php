<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AdminSettings
{
    // Do not move infrastructure credentials or encryption keys into editable settings.
    public const DEFAULTS = [
        'site_name' => 'OpenMindAI',
        'support_email' => '',
        'dashboard_note' => 'Manage your AI platform, people and operations in one place.',
        'registration_enabled' => '1',
        'registration_email_verification_enabled' => '0',
        'unusual_ip_verification_enabled' => '0',
        'guest_chat_daily_limit' => '5',
        'frontend_inference_route' => 'backend',
        'chat_daily_limit' => '200',
        'task_daily_limit' => '20',
    ];

    public function all(): array
    {
        return array_replace(self::DEFAULTS, DB::table('admin_settings')->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all());
    }

    public function get(string $key): string
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown admin setting.');
        }

        return (string) (DB::table('admin_settings')->where('key', $key)->value('value') ?? self::DEFAULTS[$key]);
    }

    public function enabled(string $key): bool
    {
        return $this->get($key) === '1';
    }

    public function limit(string $key): int
    {
        return max(0, (int) $this->get($key));
    }

    public function save(array $settings): void
    {
        $now = now();
        $rows = [];
        foreach ($settings as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                throw new \InvalidArgumentException('Unknown admin setting.');
            }
            $rows[] = ['key' => $key, 'value' => (string) $value, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('admin_settings')->upsert($rows, ['key'], ['value', 'updated_at']);
    }
}
