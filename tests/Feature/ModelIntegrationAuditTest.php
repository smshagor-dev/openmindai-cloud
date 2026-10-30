<?php

namespace Tests\Feature;

use App\Services\HostedCodeAgents;
use App\Services\HostedModelRuntime;
use App\Services\NvidiaModelProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelIntegrationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_keeps_models_active_but_requires_real_worker_configuration(): void
    {
        config(['maxine.worker_url' => null, 'maxine.worker_key' => null]);
        $catalog = app(HostedCodeAgents::class)->publicCatalog();
        $this->assertCount(42, $catalog);
        $model = collect($catalog)->firstWhere('id', 'nvidia-studio-voice');
        $this->assertTrue($model['active']);
        $this->assertFalse($model['available']);
        $this->assertSame('setup_required', $model['inference_status']);
        $this->assertNull($model['path']);
        config(['maxine.worker_url' => 'https://worker.example.test/process', 'maxine.worker_key' => str_repeat('s', 32)]);
        $model = collect(app(HostedCodeAgents::class)->publicCatalog())->firstWhere('id', 'nvidia-studio-voice');
        $this->assertTrue($model['available']);
        $this->assertFalse($model['provider_verified']);
        $this->assertStringNotContainsString(str_repeat('s', 32), json_encode($model));
    }

    public function test_retired_provider_is_listed_with_a_replacement_but_not_available(): void
    {
        DB::table('hosted_code_agents')->where('slug', 'nvidia-ising-calibration-1-35b-a3b')
            ->update(['last_test_http_status' => 410, 'last_test_status' => 'invalid_request']);
        $model = collect(app(HostedCodeAgents::class)->publicCatalog())->firstWhere('id', 'nvidia-ising-calibration-1-35b-a3b');
        $this->assertTrue($model['active']);
        $this->assertFalse($model['available']);
        $this->assertSame('retired', $model['inference_status']);
        $this->assertNull(app(HostedCodeAgents::class)->find('nvidia-ising-calibration-1-35b-a3b'));
        $this->assertFalse(app(HostedCodeAgents::class)->enabled()->contains('slug', 'nvidia-ising-calibration-1-35b-a3b'));
        $this->assertSame('nvidia-ising-calibration-1.5-31b', $model['replacement_model']);
        $this->assertNull($model['path']);
    }

    public function test_glm_catalog_slug_is_not_used_as_the_provider_identifier(): void
    {
        $this->assertDatabaseHas('hosted_code_agents', ['slug' => 'nvidia-glm-5-3', 'provider_model' => 'z-ai/glm-5.3']);
        $this->assertDatabaseHas('hosted_code_agents', ['slug' => 'nvidia-glm-5-3-flash', 'provider_model' => 'z-ai/glm-5.3-flash']);
    }

    public function test_insecure_worker_or_short_secret_never_counts_as_configured(): void
    {
        config(['voicechat.ws_url' => 'ws://worker.example.test/realtime', 'voicechat.worker_key' => 'short']);
        $result = app(HostedModelRuntime::class)->inspect('nvidia-nemotron-voicechat');
        $this->assertFalse($result['configured']);
        $this->assertSame(['voicechat.ws_url', 'voicechat.worker_key'], $result['missing_settings']);
    }

    public function test_live_probe_rejects_empty_chat_response_and_accepts_visible_content(): void
    {
        config(['models.nvidia_key' => 'private-probe-key']);
        $model = DB::table('hosted_code_agents')->where('slug', 'openmindai-lite')->first();
        Http::fakeSequence()->push(['choices' => [['message' => ['content' => '', 'reasoning_content' => 'hidden']]]])
            ->push(['choices' => [['message' => ['content' => 'OK']]]]);
        $probe = app(NvidiaModelProbe::class);
        $this->assertSame('invalid_response', $probe->run($model)['status']);
        $this->assertSame('success', $probe->run($model)['status']);
    }

    public function test_audit_without_live_flag_makes_no_requests_or_false_passes(): void
    {
        Http::fake();
        $this->artisan('models:audit', ['--model' => ['openmindai-lite']])->expectsOutputToContain('not_tested')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertNull(DB::table('hosted_code_agents')->where('slug', 'openmindai-lite')->value('last_test_status'));
    }

    public function test_live_audit_records_throttling_without_exposing_provider_body(): void
    {
        config(['models.nvidia_key' => 'private-probe-key']);
        Http::fake(['*' => Http::response(['error' => 'private-probe-key'], 429)]);
        $this->artisan('models:audit', ['--live' => true, '--model' => ['openmindai-lite']])
            ->expectsOutputToContain('rate_limited')->assertSuccessful();
        $this->assertDatabaseHas('hosted_code_agents', ['slug' => 'openmindai-lite', 'last_test_status' => 'rate_limited', 'last_test_http_status' => 429]);
    }
}
