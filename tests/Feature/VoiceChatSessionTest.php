<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HostedCodeAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VoiceChatSessionTest extends TestCase
{
    use RefreshDatabase;

    private function userKey(): array
    {
        $user = User::create([
            'name' => 'Realtime Developer',
            'email' => uniqid('voicechat-').'@example.com',
            'password' => 'GoodPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return [$user, $user->createToken('developer:Studio', ['openmindai:invoke'])->plainTextToken];
    }

    private function configureRelay(): string
    {
        $secret = str_repeat('s', 40);
        config(['voicechat.ws_url' => 'wss://voice.openmindai.org/v1/realtime',
            'voicechat.worker_key' => $secret]);

        return $secret;
    }

    private function start(string $apiKey): array
    {
        return $this->withToken($apiKey)->postJson(
            '/api/v1/openmindai/voicechat/sessions', []
        )->assertStatus(201)->assertJsonPath('status', 'issued')
            ->assertJsonPath('sample_rate', 24000)->json();
    }

    private function workerHeader(string $secret): array
    {
        return ['X-OpenMindAI-Voicechat-Worker' => $secret];
    }

    private function ticket(array $session): string
    {
        parse_str(parse_url($session['ws_url'], PHP_URL_QUERY), $query);

        return (string) $query['ticket'];
    }

    public function test_all_42_catalog_models_are_active_and_voicechat_uses_realtime_ws_sessions(): void
    {
        $this->configureRelay();
        [$user, $key] = $this->userKey();
        $developerData = $this->withToken($key)->getJson('/api/v1/openmindai/catalog')->assertOk()->json('data');
        $this->assertCount(3, $developerData);
        $items = collect(app(HostedCodeAgents::class)->publicCatalog());
        $this->assertCount(42, $items);
        $this->assertTrue($items->every(fn ($row) => $row['active'] === true));
        $voice = $items->firstWhere('id', 'nvidia-nemotron-voicechat');
        $this->assertSame('realtime.audio', $voice['operation']);
        $this->assertSame('/voicechat/sessions', $voice['path']);
        $this->assertTrue($voice['available']);
        $this->assertFalse($voice['provider_verified']);
        $this->withToken($key)->postJson('/api/v1/openmindai/models/nvidia-nemotron-voicechat/invoke', [
            'input' => ['messages' => []],
        ])->assertUnprocessable();
    }

    public function test_private_relay_configuration_is_required_before_issuing_any_session_ticket(): void
    {
        [$user, $key] = $this->userKey();
        $this->withToken($key)->postJson('/api/v1/openmindai/voicechat/sessions', [])
            ->assertStatus(503);
        config(['voicechat.ws_url' => 'ws://unsafe.example/realtime',
            'voicechat.worker_key' => str_repeat('x', 40)]);
        $this->withToken($key)->postJson('/api/v1/openmindai/voicechat/sessions', [])
            ->assertStatus(503);
        $this->assertDatabaseCount('voicechat_sessions', 0);
    }

    public function test_one_time_ticket_claim_and_successful_audio_completion_are_idempotent(): void
    {
        $secret = $this->configureRelay();
        [$user, $key] = $this->userKey();
        $issued = $this->start($key);
        $ticket = $this->ticket($issued);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $ticket);
        $this->assertStringStartsWith('wss://voice.openmindai.org/v1/realtime?ticket=', $issued['ws_url']);
        $this->assertDatabaseHas('voicechat_sessions', [
            'id' => $issued['id'], 'status' => 'issued',
            'ticket_hash' => hash('sha256', $ticket),
        ]);
        $this->assertDatabaseMissing('voicechat_sessions', ['ticket_hash' => $ticket]);

        $this->postJson('/api/v1/voicechat/worker/claim', ['ticket' => $ticket])
            ->assertUnauthorized();
        $this->withHeaders($this->workerHeader('incorrect'))->postJson(
            '/api/v1/voicechat/worker/claim', ['ticket' => $ticket],
        )->assertUnauthorized();
        $claimed = $this->withHeaders($this->workerHeader($secret))->postJson(
            '/api/v1/voicechat/worker/claim', ['ticket' => $ticket]
        )->assertOk()->assertJsonPath('id', $issued['id']);
        $this->withHeaders($this->workerHeader($secret))->postJson(
            '/api/v1/voicechat/worker/claim', ['ticket' => $ticket]
        )->assertUnauthorized();

        $this->withHeaders($this->workerHeader($secret))->postJson(
            '/api/v1/voicechat/worker/finish', [
                'id' => $issued['id'], 'produced_audio' => true,
            ]
        )->assertOk()->assertJsonPath('status', 'closed');
        $this->assertDatabaseHas('voicechat_sessions', [
            'id' => $issued['id'], 'status' => 'closed', 'produced_audio' => true,
        ]);
        $this->assertDatabaseCount('api_credit_usage', 1);
        $this->withHeaders($this->workerHeader($secret))->postJson(
            '/api/v1/voicechat/worker/finish', [
                'id' => $issued['id'], 'produced_audio' => true,
            ]
        )->assertOk();
        $this->assertDatabaseCount('api_credit_usage', 1);
        $this->app['auth']->forgetGuards();
        $this->withToken($key)->getJson('/api/v1/openmindai/voicechat/sessions/'.$issued['id'])
            ->assertOk()->assertJsonPath('produced_audio', true);
    }

    public function test_unfinished_session_does_not_charge_and_expired_ticket_cannot_be_claimed(): void
    {
        $secret = $this->configureRelay();
        [$user, $key] = $this->userKey();
        $issued = $this->start($key);
        $ticket = $this->ticket($issued);
        DB::table('voicechat_sessions')->where('id', $issued['id'])
            ->update(['expires_at' => now()->subSecond()]);
        $this->withHeaders($this->workerHeader($secret))->postJson(
            '/api/v1/voicechat/worker/claim', ['ticket' => $ticket]
        )->assertUnauthorized();
        // Issuing another ticket closes the expired unused record first.
        $this->app['auth']->forgetGuards();
        $new = $this->start($key);
        $this->withHeaders($this->workerHeader($secret))->postJson(
            '/api/v1/voicechat/worker/claim', ['ticket' => $this->ticket($new)]
        )->assertOk();
        $this->withHeaders($this->workerHeader($secret))->postJson(
            '/api/v1/voicechat/worker/finish', [
                'id' => $new['id'], 'produced_audio' => false,
            ]
        )->assertOk();
        $this->assertDatabaseCount('api_credit_usage', 0);
    }

    public function test_sessions_are_owner_scoped_and_an_active_session_prevents_duplicates(): void
    {
        $this->configureRelay();
        [$user, $key] = $this->userKey();
        $issued = $this->start($key);
        $this->withToken($key)->postJson('/api/v1/openmindai/voicechat/sessions', [])
            ->assertStatus(429);
        [$other, $otherKey] = $this->userKey();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherKey)->getJson('/api/v1/openmindai/voicechat/sessions/'.$issued['id'])
            ->assertNotFound();
        $this->withToken($otherKey)->deleteJson('/api/v1/openmindai/voicechat/sessions/'.$issued['id'])
            ->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($key)->deleteJson('/api/v1/openmindai/voicechat/sessions/'.$issued['id'])
            ->assertOk();
        $this->assertDatabaseHas('voicechat_sessions', ['id' => $issued['id'], 'status' => 'closed']);
        $this->app['auth']->forgetGuards();
        $this->start($key);
    }

    public function test_monthly_allowance_is_respected_even_for_streaming(): void
    {
        $this->configureRelay();
        [$user, $key] = $this->userKey();
        $user->forceFill(['monthly_credit_limit' => 0])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($key)->postJson('/api/v1/openmindai/voicechat/sessions', [])
            ->assertStatus(429);
        $this->assertDatabaseCount('voicechat_sessions', 0);
    }
}
