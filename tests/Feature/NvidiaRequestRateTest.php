<?php

namespace Tests\Feature;

use App\Services\NvidiaRequestRate;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NvidiaRequestRateTest extends TestCase
{
    public function test_shared_provider_window_permits_forty_calls_and_returns_retry_after_without_incrementing(): void
    {
        config(['models.nvidia_requests_per_minute' => 40, 'models.nvidia_key' => 'rate-test-only']);
        for ($i = 0; $i < 40; $i++) {
            NvidiaRequestRate::reserve();
        }
        try {
            NvidiaRequestRate::reserve();
            $this->fail('The forty-first request must wait.');
        } catch (HttpException $error) {
            $this->assertSame(429, $error->getStatusCode());
            $this->assertGreaterThanOrEqual(1, $error->getHeaders()['Retry-After']);
        }
        $key = 'nvidia:request-window:'.hash('sha256', 'rate-test-only');
        $this->assertCount(40, Cache::get($key));
        Cache::put($key, [microtime(true) - 61], 61);
        NvidiaRequestRate::reserve();
        $this->assertCount(1, Cache::get($key));
    }
}
