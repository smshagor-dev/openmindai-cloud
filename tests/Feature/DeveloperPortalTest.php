<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApiCreditUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeveloperPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_developers_can_register_and_use_their_login_for_key_management(): void
    {
        config(['developer.public_base_url' => 'https://portal.example.test/api/v1/openmindai']);
        $created = $this->postJson('/api/v1/register', [
            'name' => 'Portal Developer',
            'email' => 'new-portal@example.com',
            'password' => 'CorrectPassword123',
            'password_confirmation' => 'CorrectPassword123',
        ])->assertCreated();
        $this->assertNotEmpty($created->json('token'));

        $loginToken = $created->json('token');
        $this->withToken($loginToken)
            ->getJson('/api/v1/developer/keys')
            ->assertOk()
            ->assertJsonPath('keys', [])
            ->assertJsonPath('base_url', 'https://portal.example.test/api/v1/openmindai');

        $issued = $this->withToken($loginToken)
            ->postJson('/api/v1/developer/keys', [
                'name' => 'My desktop editor',
                'password' => 'CorrectPassword123',
            ])->assertCreated()->assertJsonStructure(['api_key', 'base_url', 'expires_at'])
            ->assertJsonPath('base_url', 'https://portal.example.test/api/v1/openmindai');

        $gatewayToken = $issued->json('api_key');
        $this->app['auth']->forgetGuards();
        $this->withToken($gatewayToken)
            ->getJson('/api/v1/openmindai/models')
            ->assertOk();
        $this->withToken($gatewayToken)
            ->getJson('/api/v1/developer/usage')
            ->assertForbidden();
    }

    public function test_developer_keys_do_not_consume_device_login_slots(): void
    {
        $user = User::create([
            'name' => 'Desktop Developer',
            'email' => 'ten-keys@example.com',
            'password' => 'CorrectPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        for ($i = 0; $i < 10; $i++) {
            $user->createToken('developer:tool-'.$i, ['openmindai:invoke'], now()->addDays(90));
        }

        $this->postJson('/api/v1/login', [
            'email' => 'ten-keys@example.com',
            'password' => 'CorrectPassword123',
            'device_name' => 'OpenMindAI Developer Portal',
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_unauthenticated_users_cannot_read_developer_usage_or_model_catalog(): void
    {
        $this->getJson('/api/v1/developer/usage')->assertUnauthorized();
        $this->getJson('/api/v1/developer/catalog')->assertUnauthorized();
    }

    public function test_usage_is_scoped_to_the_signed_in_developer(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice-portal@example.com', 'password' => 'Password123']);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob-portal@example.com', 'password' => 'Password123']);
        $alice->forceFill(['email_verified_at' => now(), 'monthly_credit_limit' => 4])->save();
        $bob->forceFill(['email_verified_at' => now()])->save();
        $credits = app(ApiCreditUsage::class);
        $connection = $credits->connection('nvidia', 'nvidia/nemotron-demo', 'code');
        $credits->completed($alice->id, $connection, 'portal:test:alice');
        $credits->completed($bob->id, $connection, 'portal:test:bob');

        $token = $alice->createToken('Portal browser', ['user'], now()->addDays(30))->plainTextToken;
        $this->withToken($token)
            ->getJson('/api/v1/developer/usage')
            ->assertOk()
            ->assertJsonPath('used', 1)
            ->assertJsonPath('limit', 4)
            ->assertJsonPath('remaining', 3)
            ->assertJsonPath('by_model.0.model', 'OpenMindAI')
            ->assertJsonPath('by_model.0.kind', 'Coding agent')
            ->assertJsonPath('by_model.0.used', 1)
            ->assertJsonPath('daily.0.used', 1);

        $this->withToken($token)
            ->getJson('/api/v1/developer/catalog')
            ->assertOk()
            ->assertJsonStructure(['data'])
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['name' => 'OpenMindAI'])
            ->assertJsonMissing(['id' => 'nvidia-background-noise-removal']);
    }
}
