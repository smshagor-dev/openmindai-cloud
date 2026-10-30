<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NvidiaRequestRate
{
    public static function reserve(): void
    {
        $limit = (int) config('models.nvidia_requests_per_minute', 40);
        if ($limit <= 0) {
            return;
        }
        $key = 'nvidia:request-window:'.hash('sha256', (string) config('models.nvidia_key'));
        Cache::lock($key.':lock', 5)->block(2, function () use ($key, $limit) {
            $now = microtime(true);
            $times = array_values(array_filter(Cache::get($key, []), fn ($time) => $time > $now - 60));
            if (count($times) >= $limit) {
                throw new HttpException(429, 'provider_rate_limit', null, ['Retry-After' => max(1, (int) ceil($times[0] + 60 - $now))]);
            }
            $times[] = $now;
            Cache::put($key, $times, 61);
        });
    }
}
