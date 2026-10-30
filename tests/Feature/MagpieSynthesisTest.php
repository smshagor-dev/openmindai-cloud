<?php

namespace Tests\Feature;

use App\Jobs\RunMagpieSynthesis;
use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\HostedCodeAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MagpieSynthesisTest extends TestCase
{
    use RefreshDatabase;

    private function account(): array
    {
        $user = User::create([
            'name' => 'Audio Developer',
            'email' => uniqid('magpie-').'@example.com',
            'password' => 'GoodPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return [$user, $user->createToken('developer:Studio', ['openmindai:invoke'])->plainTextToken];
    }

    private function wav(): string
    {
        return 'RIFF'.pack('V', 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 22050, 44100, 2, 16)
            .'data'.pack('V', 0);
    }

    private function setupNim(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['magpie.url' => 'https://speech-nim.example.test',
            'magpie.token' => str_repeat('t', 40)]);
    }

    private function submit(string $key, array $override = []): string
    {
        return $this->withToken($key)->post('/api/v1/openmindai/tts/generations', array_merge([
            'text' => 'Hello from OpenMindAI.',
            'voice' => 'Magpie-ZeroShot-Multilingual.Female',
            'language' => 'en-US',
        ], $override))->assertStatus(202)->assertJsonPath('status', 'queued')->json('id');
    }

    public function test_magpie_is_active_and_uses_queued_tts_inference(): void
    {
        $this->setupNim();
        [$user, $key] = $this->account();
        $developerData = $this->withToken($key)->getJson('/api/v1/openmindai/catalog')->assertOk()->json('data');
        $this->assertCount(3, $developerData);
        $models = collect(app(HostedCodeAgents::class)->publicCatalog());
        $tts = $models->firstWhere('id', 'nvidia-magpie-tts-zeroshot');
        $this->assertTrue($tts['active']);
        $this->assertTrue($tts['available']);
        $this->assertFalse($tts['provider_verified']);
        $this->assertSame('audio.synthesize', $tts['operation']);
        $this->assertSame('/tts/generations', $tts['path']);
        $this->withToken($key)->postJson('/api/v1/openmindai/models/nvidia-magpie-tts-zeroshot/invoke',
            ['input' => ['text' => 'wrong route']])->assertUnprocessable();
    }

    public function test_builtin_voice_generates_private_wav_and_charges_only_once(): void
    {
        $this->setupNim();
        Http::fake(['speech-nim.example.test/v1/audio/synthesize' => Http::response($this->wav(), 200, [
            'Content-Type' => 'audio/wav',
        ])]);
        [$user, $key] = $this->account();
        $id = $this->submit($key);
        Queue::assertPushed(RunMagpieSynthesis::class);
        (new RunMagpieSynthesis($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('magpie_generations', [
            'id' => $id, 'user_id' => $user->id, 'status' => 'completed',
            'encrypted_request' => '',
        ]);
        Storage::disk('local')->assertExists('magpie/'.$user->id.'/'.$id.'.wav');
        $this->withToken($key)->getJson('/api/v1/openmindai/tts/'.$id)->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('mime', 'audio/wav');
        $this->withToken($key)->get('/api/v1/openmindai/tts/'.$id.'/download')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($request) => $request->url() === 'https://speech-nim.example.test/v1/audio/synthesize'
            && $request->hasHeader('Authorization'));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('api_credit_usage', 1);
        (new RunMagpieSynthesis($id))->handle(app(ApiCreditUsage::class));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('api_credit_usage', 1);
    }

    public function test_reference_voice_requires_explicit_consent_and_is_deleted_after_inference(): void
    {
        $this->setupNim();
        Http::fake(['speech-nim.example.test/*' => Http::response($this->wav(), 200)]);
        [$user, $key] = $this->account();
        $base = ['voice' => 'Magpie-ZeroShot-Multilingual'];
        $this->withToken($key)->post('/api/v1/openmindai/tts/generations', [
            'text' => 'A sample voice', 'language' => 'en-US', ...$base,
            'audio_prompt' => UploadedFile::fake()->createWithContent('speaker.wav', $this->wav()),
        ])->assertUnprocessable();

        $id = $this->submit($key, [
            ...$base, 'voice_consent' => true,
            'audio_prompt' => UploadedFile::fake()->createWithContent('speaker.wav', $this->wav()),
        ]);
        $voicePath = DB::table('magpie_generations')->where('id', $id)->value('voice_path');
        Storage::disk('local')->assertExists($voicePath);
        (new RunMagpieSynthesis($id))->handle(app(ApiCreditUsage::class));
        Storage::disk('local')->assertMissing($voicePath);
        $this->assertDatabaseHas('magpie_generations', ['id' => $id, 'status' => 'completed']);
        Http::assertSent(fn ($request) => $request->url() === 'https://speech-nim.example.test/v1/audio/synthesize'
            && $request->hasHeader('Authorization'));
    }

    public function test_invalid_language_missing_worker_and_wrong_account_are_rejected(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $key] = $this->account();
        $this->withToken($key)->post('/api/v1/openmindai/tts/generations', [
            'text' => 'Hello.', 'language' => 'invalid',
            'voice' => 'Magpie-ZeroShot-Multilingual.Female',
        ])->assertUnprocessable();
        $this->withToken($key)->post('/api/v1/openmindai/tts/generations', [
            'text' => 'Hello.', 'language' => 'en-US',
            'voice' => 'Magpie-ZeroShot-Multilingual.Female',
        ])->assertStatus(503);

        config(['magpie.url' => 'https://speech-nim.example.test',
            'magpie.token' => str_repeat('x', 40)]);
        $id = $this->submit($key);
        [$other, $otherKey] = $this->account();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherKey)->getJson('/api/v1/openmindai/tts/'.$id)->assertNotFound();
        $this->withToken($otherKey)->get('/api/v1/openmindai/tts/'.$id.'/download')->assertNotFound();
    }

    public function test_invalid_nim_audio_does_not_charge_or_expose_download(): void
    {
        $this->setupNim();
        Http::fake(['speech-nim.example.test/*' => Http::response('not a wav', 200)]);
        [$user, $key] = $this->account();
        $id = $this->submit($key);
        (new RunMagpieSynthesis($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('magpie_generations', [
            'id' => $id, 'status' => 'failed',
            'error_code' => 'synthesis_unavailable', 'encrypted_request' => '',
        ]);
        $this->assertDatabaseCount('api_credit_usage', 0);
        $this->withToken($key)->get('/api/v1/openmindai/tts/'.$id.'/download')->assertNotFound();
    }

    public function test_one_active_generation_and_monthly_credit_limit_are_enforced(): void
    {
        $this->setupNim();
        [$user, $key] = $this->account();
        $this->submit($key);
        $this->withToken($key)->post('/api/v1/openmindai/tts/generations', [
            'text' => 'Second example', 'language' => 'en-US',
            'voice' => 'Magpie-ZeroShot-Multilingual.Male',
        ])->assertStatus(429);
        DB::table('magpie_generations')->where('user_id', $user->id)->update(['status' => 'failed']);
        $user->forceFill(['monthly_credit_limit' => 0])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($key)->post('/api/v1/openmindai/tts/generations', [
            'text' => 'Second example', 'language' => 'en-US',
            'voice' => 'Magpie-ZeroShot-Multilingual.Male',
        ])->assertStatus(429);
    }
}
