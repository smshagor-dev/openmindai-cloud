<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisteredApplicationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $withTwoFactor = false): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'administrator@example.com',
            'password' => 'CorrectPassword123',
        ]);
        $user->forceFill([
            'is_admin' => true,
            'email_verified_at' => now(),
            'two_factor_confirmed_at' => $withTwoFactor ? now() : null,
            'two_factor_secret' => $withTwoFactor ? 'JBSWY3DPEHPK3PXP' : null,
            'two_factor_recovery_codes' => $withTwoFactor ? [Hash::make('valid-recovery-code')] : [],
        ])->save();
        $this->actingAs($user)->withSession(['admin_verified' => true]);

        return $user;
    }

    private function register(User $owner, bool $enabled = true): int
    {
        return DB::table('first_party_apps')->insertGetId([
            'app_id' => 'openmindai-test-app',
            'name' => 'OpenMind Studio Server',
            'owner_user_id' => $owner->id,
            'encrypted_secret' => Crypt::encryptString('testing-secret-32-characters-long'),
            'enabled' => $enabled,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_registered_apps_show_copy_controls_but_never_expose_secret_in_html(): void
    {
        $id = $this->register($this->admin());
        $this->get('/admin/code-agents')->assertOk()
            ->assertSee('Registered applications')
            ->assertSee('openmindai-test-app')
            ->assertSee('Copy App ID')
            ->assertSee('Copy Secret')
            ->assertSee('Reveal signing secret')
            ->assertSee('Delete permanently')
            ->assertSee('/admin/code-agents/apps/'.$id.'/reveal')
            ->assertDontSee('testing-secret-32-characters-long')
            ->assertDontSee('encrypted_secret');
    }

    public function test_reveal_requires_password_and_returns_both_credentials_without_caching(): void
    {
        $id = $this->register($this->admin());
        $path = '/admin/code-agents/apps/'.$id.'/reveal';
        $this->postJson($path, ['current_password' => 'wrong'])->assertUnprocessable()
            ->assertJsonMissingPath('signing_secret');
        $this->postJson($path, [])->assertUnprocessable();
        $this->postJson($path, ['current_password' => 'CorrectPassword123'])
            ->assertOk()
            ->assertJsonPath('app_id', 'openmindai-test-app')
            ->assertJsonPath('signing_secret', 'testing-secret-32-characters-long')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_reveal_requires_two_factor_when_enabled(): void
    {
        $id = $this->register($this->admin(true));
        $path = '/admin/code-agents/apps/'.$id.'/reveal';
        $this->postJson($path, ['current_password' => 'CorrectPassword123'])->assertUnprocessable();
        $this->postJson($path, ['current_password' => 'CorrectPassword123', 'code' => 'invalid'])->assertStatus(422);
        $this->postJson($path, [
            'current_password' => 'CorrectPassword123',
            'code' => 'valid-recovery-code',
        ])->assertOk()->assertJsonPath('app_id', 'openmindai-test-app');
    }

    public function test_deletion_requires_reauthentication_and_removes_credentials(): void
    {
        $id = $this->register($this->admin());
        $path = '/admin/code-agents/apps/'.$id.'/permanent';
        $this->deleteJson($path, ['current_password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseHas('first_party_apps', ['id' => $id]);
        $this->delete($path, ['current_password' => 'CorrectPassword123'])->assertRedirect();
        $this->assertDatabaseMissing('first_party_apps', ['id' => $id]);
        $this->postJson('/admin/code-agents/apps/'.$id.'/reveal', [
            'current_password' => 'CorrectPassword123',
        ])->assertNotFound();
    }

    public function test_revoked_apps_cannot_reveal_secret_but_can_be_deleted(): void
    {
        $id = $this->register($this->admin(), false);
        $this->postJson('/admin/code-agents/apps/'.$id.'/reveal', [
            'current_password' => 'CorrectPassword123',
        ])->assertNotFound();
        $this->delete('/admin/code-agents/apps/'.$id.'/permanent', [
            'current_password' => 'CorrectPassword123',
        ])->assertRedirect();
        $this->assertDatabaseMissing('first_party_apps', ['id' => $id]);
    }

    public function test_non_admin_cannot_reveal_or_delete_app_credentials(): void
    {
        $admin = $this->admin();
        $id = $this->register($admin);
        $regular = User::create([
            'name' => 'Regular',
            'email' => 'regular@example.com',
            'password' => 'CorrectPassword123',
        ]);
        $this->actingAs($regular)->withSession(['admin_verified' => true]);
        $this->postJson('/admin/code-agents/apps/'.$id.'/reveal', [
            'current_password' => 'CorrectPassword123',
        ])->assertForbidden();
        $this->delete('/admin/code-agents/apps/'.$id.'/permanent', [
            'current_password' => 'CorrectPassword123',
        ])->assertForbidden();
        $this->assertDatabaseHas('first_party_apps', ['id' => $id]);
    }
}
