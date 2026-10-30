<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AdminSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'StrongPassword123']);
        $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($user)->withSession(['admin_verified' => true]);

        return $user;
    }

    private function settings(array $overrides = []): array
    {
        return array_replace([
            'site_name' => 'Research Console',
            'support_email' => 'help@example.com',
            'dashboard_note' => 'Manage our models and users.',
            'registration_enabled' => '0',
            'chat_daily_limit' => 45,
            'task_daily_limit' => 7,
            'current_password' => 'StrongPassword123',
        ], $overrides);
    }

    public function test_dashboard_displays_actual_database_metrics_and_sidebar(): void
    {
        $this->admin();
        $member = User::create(['name' => 'New member', 'email' => 'member@example.com', 'password' => 'StrongPassword123']);
        $conversation = Conversation::create(['user_id' => $member->id, 'title' => 'Real conversation']);
        Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Hello']);
        AiModel::create([
            'name' => 'Local model', 'family' => 'Qwen3', 'repository' => 'owner/repo',
            'revision' => str_repeat('a', 40), 'filename' => 'model.gguf',
            'sha256' => str_repeat('b', 64), 'bytes' => 123, 'license' => 'apache-2.0',
            'status' => 'ready', 'enabled' => true, 'runtime_model' => 'model',
        ]);

        $this->get('/admin')->assertOk()
            ->assertSee('New member')->assertSee('Local model')->assertSee('Conversations')
            ->assertSee('Workspace settings')->assertSee('Chat models')
            ->assertSee('Media models')->assertSee('Public assistants')
            ->assertSee('href="/admin/users"', false);
    }

    public function test_database_settings_require_admin_session_and_current_password(): void
    {
        $admin = $this->admin();
        $this->get('/admin/settings')->assertOk()->assertSee('Workspace settings');
        $this->withSession(['admin_verified' => false])
            ->patch('/admin/settings', $this->settings())->assertForbidden();
        $this->withSession(['admin_verified' => true])
            ->patch('/admin/settings', $this->settings(['current_password' => 'wrong']))
            ->assertSessionHasErrors('current_password');
        $this->assertSame('OpenMindAI', app(AdminSettings::class)->get('site_name'));
        $this->patch('/admin/settings', $this->settings())->assertRedirect('/admin/settings');
        $this->assertSame('Research Console', app(AdminSettings::class)->get('site_name'));
        $this->assertSame(45, app(AdminSettings::class)->limit('chat_daily_limit'));
        $this->assertSame(7, app(AdminSettings::class)->limit('task_daily_limit'));
        $this->assertFalse(app(AdminSettings::class)->enabled('registration_enabled'));
        $this->get('/admin')->assertSee('Research Console');
        $this->assertSame($admin->id, auth()->id());
    }

    public function test_database_settings_reject_invalid_values_and_unknown_keys(): void
    {
        $this->admin();
        $this->patch('/admin/settings', $this->settings(['chat_daily_limit' => -1]))->assertSessionHasErrors('chat_daily_limit');
        $this->patch('/admin/settings', $this->settings(['registration_enabled' => 'anything']))->assertSessionHasErrors('registration_enabled');
        $this->assertDatabaseCount('admin_settings', 0);
    }

    public function test_registration_switch_is_enforced_by_backend(): void
    {
        $this->admin();
        $this->patch('/admin/settings', $this->settings())->assertRedirect('/admin/settings');
        auth()->logout();
        $this->getJson('/api/v1/public-config')->assertOk()
            ->assertJsonPath('registration_enabled', false)
            ->assertJsonMissingPath('chat_daily_limit');

        $this->postJson('/api/v1/register', [
            'name' => 'Visitor', 'email' => 'visitor@example.com',
            'password' => '123456', 'password_confirmation' => '123456',
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'visitor@example.com']);
    }

    public function test_regular_user_cannot_open_or_change_admin_settings(): void
    {
        $member = User::create(['name' => 'Member', 'email' => 'member@example.com', 'password' => 'StrongPassword123']);
        $this->actingAs($member)->withSession(['admin_verified' => true]);
        $this->get('/admin/settings')->assertForbidden();
        $this->patch('/admin/settings', $this->settings())->assertForbidden();
        $this->assertDatabaseCount('admin_settings', 0);
    }

    public function test_administration_pages_render_the_shared_workspace_layout(): void
    {
        $this->admin();
        foreach ([
            '/admin', '/admin/users', '/admin/administrators/create',
            '/admin/profile', '/admin/models', '/admin/model-bundles',
            '/admin/assistants', '/admin/settings',
        ] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('admin-main', false)
                ->assertSee('css/admin.css', false);
        }
        $this->get('/admin/administrators/create')
            ->assertSee('admin-form-grid', false)
            ->assertSee('creator_password', false);
    }

    public function test_public_password_reset_and_admin_sign_in_do_not_require_an_admin_session(): void
    {
        $this->get('/reset-password?token=example&email=visitor%40example.com')
            ->assertOk()->assertSee('reset-password-form', false)
            ->assertSee('css/admin-auth.css', false);
        $this->get('/admin/login')
            ->assertOk()->assertSee('auth-card', false);
    }
}
