<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HostedCodeAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NvidiaSpecialistCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => 'specialist-admin@example.com', 'password' => 'GoodPassword123']);
        $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($user)->withSession(['admin_verified' => true]);

        return $user;
    }

    public function test_each_remaining_specialist_is_visible_but_not_ready(): void
    {
        $models = config('nvidia_specialists.models', []);
        $this->assertCount(11, $models);
        foreach ($models as $slug => $info) {
            $this->assertDatabaseHas('hosted_code_agents', [
                'slug' => $slug, 'enabled' => true,
                'api_ready' => isset(config('maxine.models', [])[$slug]) || isset(config('nvidia_av.routes', [])[$slug])
                    || in_array($slug, ['nvidia-streampetr', 'nvidia-cosmos-transfer2.5-2b',
                        'nvidia-magpie-tts-zeroshot', 'nvidia-nemotron-voicechat'], true),
            ]);
            $this->assertNotEmpty($info['transport']);
            $this->assertTrue(str_starts_with($info['reference'], 'https://build.nvidia.com/')
                || str_starts_with($info['reference'], 'https://docs.api.nvidia.com/')
                || str_starts_with($info['reference'], 'https://docs.nvidia.com/')
                || str_starts_with($info['reference'], 'https://developer.nvidia.com/'));
        }
    }

    public function test_admin_shows_reasons_and_does_not_allow_fake_json_activation(): void
    {
        $this->admin();
        $this->get('/admin/nvidia-models')->assertOk()
            ->assertSee('Integration needed:')
            ->assertSee('Maxine gRPC audio worker')
            ->assertSee('Official NVIDIA reference');
        $slug = 'nvidia-studio-voice';
        $this->patchJson('/admin/nvidia-models/'.$slug, [
            'api_ready' => true, 'api_path' => '/v1/embeddings',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('hosted_code_agents', ['slug' => $slug, 'api_ready' => true]);
    }

    public function test_catalog_reports_protocol_and_access_requirements(): void
    {
        $user = User::create(['name' => 'Developer', 'email' => 'specialist-user@example.com', 'password' => 'GoodPassword123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $key = $user->createToken('developer:Studio', ['openmindai:invoke'])->plainTextToken;
        $resp = $this->withToken($key)->getJson('/api/v1/openmindai/catalog')->assertOk();
        $this->assertCount(3, $resp->json('data'));
        $this->assertNull(collect($resp->json('data'))->firstWhere('id', 'nvidia-synthetic-video-detector'));
        // Specialist metadata belongs to the internal/admin catalog, not developer API.
        $internal = app(HostedCodeAgents::class)->publicCatalog();
        $this->assertCount(42, $internal);
        $model = collect($internal)->firstWhere('id', 'nvidia-synthetic-video-detector');
        $this->assertFalse($model['provider_verified']);
        $this->assertSame('grpc_stream', $model['integration_requirements']['transport']);
        $this->assertStringContainsString('Private access', $model['integration_requirements']['requirement']);
        $cosmos = collect($internal)->firstWhere('id', 'nvidia-cosmos3-nano');
        $this->assertSame('generate', $cosmos['operation']);
    }
}
