<?php

namespace Tests\Feature;

use App\Services\Studio\Catalog;
use App\Services\Studio\Readiness;
use App\Services\Studio\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StudioReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_worker_configuration_fails_without_revealing_secrets(): void
    {
        config(['studio.worker_url' => '', 'studio.worker_key' => 'secret-value']);
        $this->mock(Catalog::class)->shouldReceive('all')->andReturn(['data' => []]);
        $this->mock(Worker::class)->shouldNotReceive('health');
        $result = app(Readiness::class)->inspect();
        $this->assertFalse($result['cpu_infrastructure_ready']);
        $this->assertStringNotContainsString('secret-value', json_encode($result));
        $this->artisan('studio:preflight')->assertExitCode(1);
    }

    public function test_verified_cpu_does_not_certify_gpu_or_queue_liveness(): void
    {
        config(['studio.worker_url' => 'https://worker.example', 'studio.worker_key' => str_repeat('a', 40)]);
        $this->mock(Worker::class)->shouldReceive('health')->andReturn(['protocol' => 1, 'ready' => true, 'processors' => ['import', 'inspect', 'smooth', 'decimate', 'repair', 'transform', 'export', 'preprocess', 'compose']]);
        $this->mock(Catalog::class)->shouldReceive('all')->andReturn(['data' => [['id' => 'procedural', 'available' => true], ['id' => 'hunyuan3d-mini', 'available' => false]]]);
        $result = app(Readiness::class)->inspect();
        $this->assertTrue($result['cpu_infrastructure_ready']);
        $this->assertFalse($result['gpu_infrastructure_ready']);
        $this->artisan('studio:preflight')->assertExitCode(0);
        $this->artisan('studio:preflight --require-gpu')->assertExitCode(1);
    }

    public function test_unsafe_transport_is_rejected_before_connecting(): void
    {
        config(['studio.worker_url' => 'https://username@worker.example', 'studio.worker_key' => str_repeat('a', 40)]);
        $this->mock(Worker::class)->shouldNotReceive('health');
        $this->mock(Catalog::class)->shouldReceive('all')->andReturn(['data' => []]);
        $this->assertFalse(app(Readiness::class)->inspect()['worker']['transport_ready']);
    }

    public function test_worker_never_sends_signed_credentials_to_an_invalid_origin(): void
    {
        Http::fake();
        foreach (['http://worker.example', 'https://user@worker.example', 'https://worker.example/base', 'https://worker.example?key=bad', 'https://worker.example#fragment'] as $url) {
            config(['studio.worker_url' => $url, 'studio.worker_key' => str_repeat('a', 40)]);
            try {
                app(Worker::class)->request('GET', '/health');
                $this->fail('Unsafe worker origin was accepted.');
            } catch (HttpException $error) {
                $this->assertSame(503, $error->getStatusCode());
            }
        }
        Http::assertNothingSent();
    }
}
