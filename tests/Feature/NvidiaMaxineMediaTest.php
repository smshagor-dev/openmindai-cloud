<?php

namespace Tests\Feature;

use App\Jobs\RunMaxineInference;
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

class NvidiaMaxineMediaTest extends TestCase
{
    use RefreshDatabase;

    private function account(): array
    {
        $user = User::create([
            'name' => 'Media Developer',
            'email' => uniqid('maxine-').'@example.com',
            'password' => 'TestPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return [$user, $user->createToken('developer:Studio', ['openmindai:invoke'])->plainTextToken];
    }

    private function wav(): string
    {
        return 'RIFF'.pack('V', 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', 0);
    }

    private function begin(string $token, string $slug): string
    {
        $file = UploadedFile::fake()->createWithContent('voice.wav', $this->wav());

        return $this->withToken($token)->post('/api/v1/openmindai/maxine/'.$slug.'/jobs',
            ['file' => $file])->assertStatus(202)->assertJsonPath('status', 'queued')->json('id');
    }

    public function test_catalog_contains_three_dedicated_maxine_routes_without_claiming_provider_verification(): void
    {
        config(['maxine.worker_url' => 'https://maxine-worker.example.test', 'maxine.worker_key' => str_repeat('t', 40)]);
        [$user, $token] = $this->account();
        $response = $this->withToken($token)->getJson('/api/v1/openmindai/catalog')->assertOk();
        $this->assertCount(3, $response->json('data'));
        $rows = collect(app(HostedCodeAgents::class)->publicCatalog());
        foreach (array_keys(config('maxine.models')) as $slug) {
            $model = $rows->firstWhere('id', $slug);
            $this->assertSame('media.process', $model['operation']);
            $this->assertSame('/maxine/'.$slug.'/jobs', $model['path']);
            $this->assertFalse($model['provider_verified']);
        }
        $this->assertCount(42, $rows);
    }

    public function test_wav_inference_is_queued_private_and_charged_once_on_success(): void
    {
        Storage::fake('local');
        Queue::fake();
        config([
            'maxine.worker_url' => 'https://maxine-worker.example.test',
            'maxine.worker_key' => str_repeat('t', 40),
        ]);
        Http::fake(['maxine-worker.example.test/infer' => Http::response([
            'mime' => 'audio/wav', 'data' => base64_encode($this->wav()),
        ])]);
        [$user, $token] = $this->account();
        $id = $this->begin($token, 'nvidia-background-noise-removal');
        $this->assertDatabaseHas('maxine_jobs', ['id' => $id, 'status' => 'queued', 'user_id' => $user->id]);
        Queue::assertPushed(RunMaxineInference::class);

        (new RunMaxineInference($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('maxine_jobs', [
            'id' => $id, 'status' => 'completed', 'output_mime' => 'audio/wav',
        ]);
        $this->withToken($token)->getJson('/api/v1/openmindai/maxine/jobs/'.$id)
            ->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('mime', 'audio/wav');
        $savedPath = DB::table('maxine_jobs')->where('id', $id)->value('output_path');
        $this->assertNotEmpty($savedPath);
        Storage::disk('local')->assertExists($savedPath);
        $this->withToken($token)->get('/api/v1/openmindai/maxine/jobs/'.$id.'/download')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($r) => $r->url() === 'https://maxine-worker.example.test/infer'
            && $r['model'] === 'nvidia-background-noise-removal'
            && base64_decode($r['data'], true) === $this->wav());
        $this->assertDatabaseCount('api_credit_usage', 1);
        (new RunMaxineInference($id))->handle(app(ApiCreditUsage::class));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('api_credit_usage', 1);
    }

    public function test_studio_voice_accepts_wav_and_body_pose_accepts_mp4(): void
    {
        Storage::fake('local');
        Queue::fake();
        config([
            'maxine.worker_url' => 'https://maxine-worker.example.test',
            'maxine.worker_key' => str_repeat('x', 40),
        ]);
        Http::fake(['maxine-worker.example.test/*' => Http::response([
            'mime' => 'audio/wav', 'data' => base64_encode($this->wav()),
        ])]);
        [$user, $token] = $this->account();
        $id = $this->begin($token, 'nvidia-studio-voice');
        (new RunMaxineInference($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('maxine_jobs', ['id' => $id, 'status' => 'completed']);
        $this->withToken($token)->post('/api/v1/openmindai/maxine/nvidia-3d-body-pose/jobs', [
            'file' => UploadedFile::fake()->createWithContent('wrong.wav', $this->wav()),
            'bbox_file' => UploadedFile::fake()->createWithContent('bbox.txt', "1\n0 0 1 2 3 4\n"),
        ])->assertUnprocessable();
    }

    public function test_active_speaker_diarization_creates_private_mp4_output(): void
    {
        Storage::fake('local');
        Queue::fake();
        config([
            'maxine.worker_url' => 'https://maxine-worker.example.test',
            'maxine.worker_key' => str_repeat('t', 40),
        ]);
        $mp4 = "\x00\x00\x00\x18ftypisom0000000000000000";
        Http::fake(['maxine-worker.example.test/*' => Http::response([
            'mime' => 'video/mp4', 'data' => base64_encode($mp4),
        ])]);
        [$user, $token] = $this->account();
        $video = fn () => UploadedFile::fake()->createWithContent('clip.mp4', $mp4);
        $path = '/api/v1/openmindai/maxine/nvidia-active-speaker-detection/jobs';
        $this->withToken($token)->post($path, ['file' => $video()])->assertUnprocessable();
        $this->withToken($token)->post($path, [
            'file' => $video(),
            'diarization_file' => UploadedFile::fake()->createWithContent('speakers.json', '{"segments":[]}'),
        ])->assertStatus(202);
        $id = DB::table('maxine_jobs')->where('user_id', $user->id)->value('id');
        (new RunMaxineInference($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('maxine_jobs', [
            'id' => $id, 'status' => 'completed', 'output_mime' => 'video/mp4',
        ]);
        Http::assertSent(fn ($r) => $r['model'] === 'nvidia-active-speaker-detection'
            && json_decode(base64_decode($r['diarization'], true), true) === ['segments' => []]);
        $this->withToken($token)->get('/api/v1/openmindai/maxine/jobs/'.$id.'/download')->assertOk();
    }

    public function test_synthetic_video_detector_returns_owner_only_csv_report(): void
    {
        Storage::fake('local');
        Queue::fake();
        config([
            'maxine.worker_url' => 'https://maxine-worker.example.test',
            'maxine.worker_key' => str_repeat('t', 40),
        ]);
        $csv = "index,probability\n0,0.100000\n";
        Http::fake(['maxine-worker.example.test/*' => Http::response([
            'mime' => 'text/csv', 'data' => base64_encode($csv),
        ])]);
        [$user, $token] = $this->account();
        $video = "\x00\x00\x00\x18ftypisom0000000000000000";
        $response = $this->withToken($token)->post(
            '/api/v1/openmindai/maxine/nvidia-synthetic-video-detector/jobs',
            ['file' => UploadedFile::fake()->createWithContent('sample.mp4', $video)]
        )->assertStatus(202);
        $id = $response->json('id');
        (new RunMaxineInference($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('maxine_jobs', [
            'id' => $id, 'status' => 'completed', 'output_mime' => 'text/csv',
        ]);
        $this->withToken($token)->get('/api/v1/openmindai/maxine/jobs/'.$id.'/download')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertDatabaseCount('api_credit_usage', 1);
    }

    public function test_media_job_cannot_be_read_by_other_account_or_invalid_token(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['maxine.worker_url' => 'https://maxine-worker.example.test',
            'maxine.worker_key' => str_repeat('t', 40)]);
        [$owner, $ownerKey] = $this->account();
        $id = $this->begin($ownerKey, 'nvidia-studio-voice');
        [$other, $otherKey] = $this->account();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherKey)->getJson('/api/v1/openmindai/maxine/jobs/'.$id)->assertNotFound();
        $this->withToken($otherKey)->get('/api/v1/openmindai/maxine/jobs/'.$id.'/download')->assertNotFound();

        $limited = $other->createToken('web', ['read:profile'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($limited)->post('/api/v1/openmindai/maxine/nvidia-studio-voice/jobs', [
            'file' => UploadedFile::fake()->createWithContent('input.wav', $this->wav()),
        ])->assertForbidden();
    }

    public function test_provider_errors_are_not_charged_and_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['maxine.worker_url' => 'https://maxine-worker.example.test',
            'maxine.worker_key' => str_repeat('t', 40)]);
        Http::fake(['maxine-worker.example.test/infer' => Http::response([
            'mime' => 'audio/wav', 'data' => base64_encode('not wav'),
        ])]);
        [$user, $key] = $this->account();
        $id = $this->begin($key, 'nvidia-studio-voice');
        (new RunMaxineInference($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('maxine_jobs', ['id' => $id, 'status' => 'failed',
            'error_code' => 'inference_unavailable']);
        $this->withToken($key)->get('/api/v1/openmindai/maxine/jobs/'.$id.'/download')->assertNotFound();
        $this->assertDatabaseCount('api_credit_usage', 0);
    }

    public function test_one_active_job_per_user_and_account_credit_limits(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['maxine.worker_url' => 'https://maxine-worker.example.test',
            'maxine.worker_key' => str_repeat('t', 40)]);
        [$user, $key] = $this->account();
        $this->begin($key, 'nvidia-studio-voice');
        $this->withToken($key)->post('/api/v1/openmindai/maxine/nvidia-studio-voice/jobs', [
            'file' => UploadedFile::fake()->createWithContent('voice.wav', $this->wav()),
        ])->assertStatus(429);
        DB::table('maxine_jobs')->where('user_id', $user->id)->update(['status' => 'failed']);
        $user->forceFill(['monthly_credit_limit' => 0])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($key)->post('/api/v1/openmindai/maxine/nvidia-studio-voice/jobs', [
            'file' => UploadedFile::fake()->createWithContent('voice.wav', $this->wav()),
        ])->assertStatus(429);
    }
}
