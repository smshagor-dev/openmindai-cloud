<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudApiAuthResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_token_on_model_catalog_returns_json_401_without_login_redirect(): void
    {
        $this->get('/api/v1/openmindai/models', ['Accept' => 'text/html'])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_invalid_token_on_chat_endpoint_returns_json_401(): void
    {
        $this->withToken('invalid-developer-key')
            ->post('/api/v1/openmindai/chat/completions', [], ['Accept' => 'text/html'])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_valid_developer_key_returns_model_list_even_without_json_accept(): void
    {
        $user = User::create([
            'name' => 'Cloud Developer',
            'email' => 'cloud-developer@example.com',
            'password' => 'TestPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $apiKey = $user->createToken('developer:Studio', ['openmindai:invoke'])->plainTextToken;
        $this->withToken($apiKey)
            ->get('/api/v1/openmindai/models', ['Accept' => 'text/html'])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('object', 'list');
    }

    public function test_admin_page_still_redirects_guests_to_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
