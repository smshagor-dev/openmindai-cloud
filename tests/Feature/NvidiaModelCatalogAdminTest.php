<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HostedCodeAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NvidiaModelCatalogAdminTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'nvidia-admin@example.com',
            'password' => 'TestPassword123',
        ]);
        $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($user)->withSession(['admin_verified' => true]);

        return $user;
    }

    public function test_admin_sees_all_42_models_and_can_filter_categories(): void
    {
        $this->administrator();

        $this->get('/admin/nvidia-models')->assertOk()
            ->assertSee('Free endpoint model catalog')
            ->assertSee('nemotron-3.5-embed-1b')
            ->assertSee('magpie-tts-zeroshot')
            ->assertSee('OpenMindAI Super');
        $this->assertDatabaseCount('hosted_code_agents', 42);
        $this->assertDatabaseMissing('hosted_code_agents', ['enabled' => false]);
        $this->assertSame(42, DB::table('hosted_code_agents')->where('api_ready', true)->count());
        $this->get('/admin/nvidia-models?category=Embeddings')->assertOk()
            ->assertSee('nemotron-3.5-embed-1b')
            ->assertDontSee('magpie-tts-zeroshot');
        $this->assertDatabaseCount('hosted_code_agents', 42);
    }

    public function test_all_models_are_active_without_falsely_passing_live_verification(): void
    {
        $this->administrator();
        $response = $this->get('/admin/nvidia-models')->assertOk()
            ->assertSee('Active catalog models')
            ->assertSee('Route configured')
            ->assertSee('NVIDIA live test')
            ->assertDontSee('API configured · not verified')
            ->assertDontSee('Verified (JSON API)');
        $this->assertSame(42, DB::table('hosted_code_agents')->where('enabled', true)->count());

        $user = User::create([
            'name' => 'Desktop Developer', 'email' => 'catalog-developer@example.com',
            'password' => 'TestPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $apiKey = $user->createToken('developer:Studio', ['openmindai:invoke'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $catalog = $this->withToken($apiKey)->getJson('/api/v1/openmindai/catalog')->assertOk()->json('data');
        $this->assertCount(3, $catalog);
        $this->assertSame(['openmindai-lite', 'openmindai-super', 'openmindai-ultra'], collect($catalog)->pluck('id')->sort()->values()->all());
        foreach ($catalog as $model) {
            $this->assertSame('OpenMindAI', $model['name']);
            $this->assertTrue($model['active']);
        }

        // The administrator and non-developer apps still see the complete
        // specialist catalog; the public developer projection is deliberately smaller.
        $internal = app(HostedCodeAgents::class)->publicCatalog();
        $this->assertCount(42, $internal);
        $voice = collect($internal)->firstWhere('id', 'nvidia-nemotron-voicechat');
        $this->assertFalse($voice['provider_verified']);
        $this->assertTrue($voice['active']);
    }

    public function test_admin_configures_api_and_models_remain_visible(): void
    {
        $this->administrator();
        $slug = 'nvidia-nemotron-3.5-embed-1b';
        DB::table('hosted_code_agents')->where('slug', $slug)
            ->update(['api_path' => null, 'api_ready' => false]);

        $this->patchJson('/admin/nvidia-models/'.$slug, [
            'enabled' => false,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('hosted_code_agents', ['slug' => $slug, 'enabled' => true]);

        $this->patchJson('/admin/nvidia-models/'.$slug, [
            'api_ready' => true,
        ])->assertStatus(422);

        $this->patchJson('/admin/nvidia-models/'.$slug, [
            'api_ready' => true,
            'api_protocol' => 'json',
            'api_path' => '/v1/embeddings',
            'include_model' => true,
            'name' => 'Nemotron Embed 1B',
            'provider_model' => 'nvidia/nemotron-3.5-embed-1b',
        ])->assertRedirect();

        $this->assertDatabaseHas('hosted_code_agents', [
            'slug' => $slug, 'enabled' => true, 'api_ready' => true,
            'api_path' => '/v1/embeddings', 'name' => 'Nemotron Embed 1B',
        ]);

        $this->patchJson('/admin/nvidia-models/'.$slug, [
            'api_ready' => true,
            'api_path' => '/v1/https://attacker.example/',
        ])->assertUnprocessable();
    }

    public function test_regular_users_cannot_access_nvidia_admin_controls(): void
    {
        $member = User::create([
            'name' => 'Member',
            'email' => 'nvidia-member@example.com',
            'password' => 'TestPassword123',
        ]);
        $this->actingAs($member)->withSession(['admin_verified' => true]);

        $this->get('/admin/nvidia-models')->assertForbidden();
        $this->patch('/admin/nvidia-models/nvidia-nemotron-3.5-embed-1b', [
            'enabled' => true,
        ])->assertForbidden();
        $this->assertSame(42, DB::table('hosted_code_agents')->where('enabled', true)->count());
    }
}
