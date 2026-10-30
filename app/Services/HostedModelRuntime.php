<?php

namespace App\Services;

class HostedModelRuntime
{
    /** Configuration readiness only; never proof of provider entitlement. */
    public function inspect(string $slug): array
    {
        $settings = match (true) {
            isset(config('maxine.models', [])[$slug]) => ['maxine.worker_url' => 'https', 'maxine.worker_key' => 'secret'],
            isset(config('nvidia_av.routes', [])[$slug]) => ['nvidia_av.worker_url' => 'https', 'nvidia_av.worker_token' => 'secret'],
            $slug === 'nvidia-magpie-tts-zeroshot' => ['magpie.url' => 'https', 'magpie.token' => 'secret'],
            $slug === 'nvidia-nemotron-voicechat' => ['voicechat.ws_url' => 'wss', 'voicechat.worker_key' => 'secret'],
            $slug === 'nvidia-cosmos-transfer2.5-2b' => ['cosmos_transfer.url' => 'https', 'cosmos_transfer.token' => 'secret'],
            default => [],
        };
        $missing = [];
        foreach ($settings as $name => $type) {
            $value = (string) config($name);
            $parts = $type === 'secret' ? [] : parse_url($value);
            $valid = $type === 'secret' ? strlen($value) >= 32
                : is_array($parts) && (($parts['scheme'] ?? '') === $type || ($type === 'https' && ($parts['scheme'] ?? '') === 'http' && ($parts['host'] ?? '') === '127.0.0.1' && isset($parts['port']))) && ! empty($parts['host'])
                    && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment']) && ! isset($parts['query']);
            if (! $valid) {
                $missing[] = $name;
            }
        }

        return ['configured' => $missing === [], 'missing_settings' => $missing];
    }

    public function configured(string $slug): bool
    {
        return $this->inspect($slug)['configured'];
    }
}
