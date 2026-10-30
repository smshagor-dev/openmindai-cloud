<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDeveloperApiKeysTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(bool $twoFactor = false): User
    {
        $user = User::create([
            'name' => 'Administrator',
            'email' => 'owner@example.com',
            'password' => 'CorrectPassword123',
        ]);
        $user->forceFill([
            'is_admin' => true,
            'email_verified_at' => now(),
            'two_factor_confirmed_at' => $twoFactor ? now() : null,
            'two_factor_secret' => $twoFactor ? 'JBSWY3DPEHPK3PXP' : null,
            'two_factor_recovery_codes' => $twoFactor ? [Hash::make('one-time-recovery')] : [],
        ])->save();
        $this->actingAs($user)->withSession(['admin_verified' => true]);

        return $user;
    }

    public function test_admin_sees_developer_api_key_menu_without_private_values(): void
    {
        $user = $this->administrator();
        $token = $user->createToken('developer:Older Studio', ['openmindai:invoke'], now()->addDays(90));
        $this->get('/admin/code-agents')->assertOk()
            ->assertSee('Developer API Keys for OpenMind Studio')
            ->assertSee('Generate Developer API Key')
            ->assertSee('Copy API Key')
            ->assertSee('Older Studio')
            ->assertDontSee($token->plainTextToken);
    }

    public function test_creation_requires_password_and_returns_once_with_correct_scope(): void
    {
        $user = $this->administrator();
        $path = '/admin/code-agents/developer-keys';
        $this->postJson($path, ['name' => 'Studio', 'current_password' => 'bad'])->assertUnprocessable();
        config(['developer.public_base_url' => 'https://portal.example.test/api/v1/openmindai']);
        $response = $this->postJson($path, [
            'name' => 'Studio Desktop', 'current_password' => 'CorrectPassword123',
        ])->assertCreated()->assertJsonStructure(['id', 'api_key', 'base_url', 'expires_at'])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('base_url', 'https://portal.example.test/api/v1/openmindai');
        $value = $response->json('api_key');
        $this->assertNotEmpty($value);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $response->json('id'),
            'tokenable_id' => $user->id,
            'name' => 'developer:Studio Desktop',
            'abilities' => '["openmindai:invoke"]',
            'token' => hash('sha256', explode('|', $value, 2)[1]),
        ]);
        $this->get('/admin/code-agents')->assertOk()->assertDontSee($value);

        // Authenticate the same kind of key as the desktop client uses.
        $this->app['auth']->forgetGuards();
        $this->withToken($value)->getJson('/api/v1/openmindai/models')->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_2fa_is_required_when_configured(): void
    {
        $this->administrator(true);
        $path = '/admin/code-agents/developer-keys';
        $this->postJson($path, ['name' => 'Studio', 'current_password' => 'CorrectPassword123'])
            ->assertUnprocessable();
        $this->postJson($path, [
            'name' => 'Studio', 'current_password' => 'CorrectPassword123',
            'code' => 'wrong-code',
        ])->assertUnprocessable();
        $this->postJson($path, [
            'name' => 'Studio', 'current_password' => 'CorrectPassword123',
            'code' => 'one-time-recovery',
        ])->assertCreated();
    }

    public function test_key_limit_and_revocation_are_restricted_to_current_admin(): void
    {
        $user = $this->administrator();
        $other = User::create([
            'name' => 'Another',
            'email' => 'another@example.com',
            'password' => 'CorrectPassword123',
        ]);
        $otherToken = $other->createToken('developer:Different user', ['openmindai:invoke']);
        $this->delete('/admin/code-agents/developer-keys/'.$otherToken->accessToken->id, [
            'current_password' => 'CorrectPassword123',
        ])->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);

        for ($i = 0; $i < 10; $i++) {
            $user->createToken('developer:Desktop '.$i, ['openmindai:invoke']);
        }
        $this->postJson('/admin/code-agents/developer-keys', [
            'name' => 'Extra key', 'current_password' => 'CorrectPassword123',
        ])->assertUnprocessable();

        $token = $user->tokens()->where('name', 'developer:Desktop 0')->firstOrFail();
        $this->delete('/admin/code-agents/developer-keys/'.$token->id, [
            'current_password' => 'incorrect',
        ])->assertSessionHasErrors('current_password');
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
        $this->delete('/admin/code-agents/developer-keys/'.$token->id, [
            'current_password' => 'CorrectPassword123',
        ])->assertRedirect();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_non_admin_cannot_generate_or_revoke_developer_api_keys(): void
    {
        $admin = $this->administrator();
        $token = $admin->createToken('developer:Studio', ['openmindai:invoke']);
        $member = User::create([
            'name' => 'Regular User',
            'email' => 'member@example.com',
            'password' => 'CorrectPassword123',
        ]);
        $this->actingAs($member)->withSession(['admin_verified' => true]);
        $this->postJson('/admin/code-agents/developer-keys', [
            'name' => 'Unauthorized key', 'current_password' => 'CorrectPassword123',
        ])->assertForbidden();
        $this->delete('/admin/code-agents/developer-keys/'.$token->accessToken->id, [
            'current_password' => 'CorrectPassword123',
        ])->assertForbidden();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }
}
