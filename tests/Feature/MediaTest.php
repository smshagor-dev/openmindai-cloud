<?php

namespace Tests\Feature;

use App\Jobs\RunMediaTask;
use App\Models\MediaTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Media', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
    }

    private function setupEngine(): User
    {
        config(['media.url' => 'http://local-engine:8090', 'media.key' => str_repeat('x', 32), 'media.kinds' => ['image', 'vision', 'transcription', 'speech', 'video']]);
        Storage::fake('local');
        Queue::fake();
        $user = $this->user();
        Sanctum::actingAs($user);

        return $user;
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=');
    }

    public function test_hosted_image_uses_existing_frontend_secret_and_saves_private_png(): void
    {
        $user = $this->setupEngine();
        config([
            'media.url' => null,
            'media.key' => null,
            'models.frontend_chat_key' => str_repeat('s', 32),
        ]);
        $headers = ['X-Chat-Bridge-Key' => str_repeat('s', 32)];
        $created = $this->withHeaders($headers)
            ->postJson('/api/v1/media/hosted/begin', ['prompt' => 'Sunrise'])
            ->assertStatus(202)->assertJsonPath('kind', 'image')
            ->assertJsonPath('status', 'running')->assertJsonMissingPath('output_path');
        Queue::assertNothingPushed();
        $id = $created->json('id');
        $this->postJson('/api/v1/media/'.$id.'/hosted/finish', [
            'mime' => 'image/png', 'data' => base64_encode($this->png()),
        ])->assertOk()->assertJsonPath('status', 'complete')->assertJsonPath('output_mime', 'image/png')
            ->assertJsonMissingPath('output_path');
        $output = $this->get('/api/v1/media/'.$id.'/output')->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->assertSame($this->png(), $output->streamedContent());
        Storage::disk('local')->assertExists('media/'.$user->id.'/'.$id.'.output');
        $this->assertDatabaseHas('media_daily_usage', [
            'user_id' => $user->id, 'kind' => 'image', 'requests' => 1,
        ]);
    }

    public function test_hosted_image_preserves_jpeg_format_in_download(): void
    {
        $this->setupEngine();
        config(['models.frontend_chat_key' => str_repeat('s', 32)]);
        $this->withHeaders(['X-Chat-Bridge-Key' => str_repeat('s', 32)]);
        $id = $this->postJson('/api/v1/media/hosted/begin', ['prompt' => 'Amber sunset'])
            ->assertStatus(202)->json('id');
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAYEBQYFBAYGBQYHBwYIChAKCgkJChQODwwQFxQYGBcUFhYaHSUfGhsjHBYWICwgIyYnKSopGR8tMC0oMCUoKSj/2wBDAQcHBwoIChMKChMoGhYaKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCj/wAARCAACAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDiqKKK+uPmD//Z');
        $this->postJson('/api/v1/media/'.$id.'/hosted/finish', [
            'mime' => 'image/jpeg', 'data' => base64_encode($jpeg),
        ])->assertOk()->assertJsonPath('output_mime', 'image/jpeg');
        $download = $this->get('/api/v1/media/'.$id.'/output')->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($jpeg, $download->streamedContent());
    }

    public function test_hosted_image_bridge_rejects_missing_key_and_bad_images(): void
    {
        $this->setupEngine();
        config(['models.frontend_chat_key' => str_repeat('s', 32)]);
        $this->postJson('/api/v1/media/hosted/begin', ['prompt' => 'Sunrise'])->assertForbidden();
        $this->withHeaders(['X-Chat-Bridge-Key' => str_repeat('s', 32)]);
        $created = $this->postJson('/api/v1/media/hosted/begin', ['prompt' => 'Sunrise'])->assertStatus(202);
        $id = $created->json('id');
        $this->postJson('/api/v1/media/'.$id.'/hosted/finish', [
            'mime' => 'image/jpeg', 'data' => base64_encode($this->png()),
        ])->assertStatus(422);
        $this->getJson('/api/v1/media/'.$id)->assertJsonPath('status', 'running');
        $this->postJson('/api/v1/media/'.$id.'/hosted/fail', [])->assertNoContent();
        $this->getJson('/api/v1/media/'.$id)->assertJsonPath('status', 'failed');
        $this->get('/api/v1/media/'.$id.'/output')->assertNotFound();
    }

    public function test_hosted_image_shared_quota_and_task_ownership(): void
    {
        $user = $this->setupEngine();
        config([
            'models.frontend_chat_key' => str_repeat('s', 32),
            'media.image_daily_limit' => 1,
        ]);
        $this->withHeaders(['X-Chat-Bridge-Key' => str_repeat('s', 32)]);
        $id = $this->postJson('/api/v1/media/hosted/begin', ['prompt' => 'First'])
            ->assertStatus(202)->json('id');
        $this->postJson('/api/v1/media/hosted/begin', ['prompt' => 'Second'])->assertStatus(429);
        $other = $this->user();
        $this->assertNotSame($other->id, $user->id);
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/media/'.$id.'/hosted/finish', [
            'mime' => 'image/png', 'data' => base64_encode($this->png()),
        ])->assertNotFound();
        $this->postJson('/api/v1/media/'.$id.'/hosted/fail', [])->assertNotFound();
    }

    public function test_hosted_speech_transcription_is_saved_under_its_owner(): void
    {
        $this->setupEngine();
        config(['models.frontend_chat_key' => str_repeat('s', 32)]);
        $this->postJson('/api/v1/media/hosted/task/begin', [
            'kind' => 'transcription', 'prompt' => '',
        ])->assertForbidden();
        $this->withHeaders(['X-Chat-Bridge-Key' => str_repeat('s', 32)]);
        $start = $this->postJson('/api/v1/media/hosted/task/begin', [
            'kind' => 'transcription', 'prompt' => '',
        ])->assertStatus(202)->assertJsonPath('status', 'running');
        $id = $start->json('id');
        Queue::assertNothingPushed();
        $this->postJson('/api/v1/media/'.$id.'/hosted/task/complete', [
            'text' => 'Spoken words converted to text',
        ])->assertOk()->assertJsonPath('status', 'complete')
            ->assertJsonPath('result_text', 'Spoken words converted to text');
        $this->getJson('/api/v1/media/'.$id)->assertJsonMissingPath('output_path');
        $this->postJson('/api/v1/media/'.$id.'/hosted/task/complete', [
            'text' => 'Cannot replace finished data',
        ])->assertStatus(409);
    }

    public function test_generated_documents_are_searchable_in_private_media_library(): void
    {
        $this->setupEngine();
        config(['models.frontend_chat_key' => str_repeat('s', 32)]);
        $this->withHeaders(['X-Chat-Bridge-Key' => str_repeat('s', 32)]);
        $created = $this->postJson('/api/v1/media/hosted/task/begin', [
            'kind' => 'document_create', 'prompt' => 'A summary',
        ])->assertStatus(202);
        $id = $created->json('id');

        $this->getJson('/api/v1/media?kind=document_create')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.kind', 'document_create');

        $other = $this->user();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/media?kind=document_create')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_deleting_running_hosted_image_rejects_late_completion(): void
    {
        $user = $this->setupEngine();
        config(['models.frontend_chat_key' => str_repeat('s', 32)]);
        $this->withHeaders(['X-Chat-Bridge-Key' => str_repeat('s', 32)]);
        $id = $this->postJson('/api/v1/media/hosted/begin', ['prompt' => 'Sunset'])
            ->assertStatus(202)->json('id');

        $this->deleteJson('/api/v1/media/'.$id)->assertNoContent();
        $this->postJson('/api/v1/media/'.$id.'/hosted/finish', [
            'mime' => 'image/png', 'data' => base64_encode($this->png()),
        ])->assertNotFound();
        $this->postJson('/api/v1/media/'.$id.'/hosted/fail', [])->assertNotFound();
        $this->getJson('/api/v1/media/'.$id)->assertNotFound();
        Storage::disk('local')->assertMissing('media/'.$user->id.'/'.$id.'.output');
    }

    public function test_deleting_running_hosted_audio_rejects_late_completion(): void
    {
        $user = $this->setupEngine();
        config(['models.frontend_chat_key' => str_repeat('s', 32)]);
        $this->withHeaders(['X-Chat-Bridge-Key' => str_repeat('s', 32)]);
        $id = $this->postJson('/api/v1/media/hosted/task/begin', [
            'kind' => 'speech', 'prompt' => 'Hello',
        ])->assertStatus(202)->json('id');

        $this->deleteJson('/api/v1/media/'.$id)->assertNoContent();
        $wav = 'RIFF'.pack('V', 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', 0);
        $this->postJson('/api/v1/media/'.$id.'/hosted/task/complete', [
            'mime' => 'audio/wav', 'data' => base64_encode($wav),
        ])->assertNotFound();
        $this->postJson('/api/v1/media/'.$id.'/hosted/task/fail', [])->assertNotFound();
        $this->get('/api/v1/media/'.$id.'/output')->assertNotFound();
        Storage::disk('local')->assertMissing('media/'.$user->id.'/'.$id.'.output');
    }

    public function test_hosted_audio_and_document_formats_are_validated_before_storage(): void
    {
        $this->setupEngine();
        config(['models.frontend_chat_key' => str_repeat('s', 32)]);
        $this->withHeaders(['X-Chat-Bridge-Key' => str_repeat('s', 32)]);
        $speech = $this->postJson('/api/v1/media/hosted/task/begin', [
            'kind' => 'speech', 'prompt' => 'Hello',
        ])->assertStatus(202)->json('id');
        $this->postJson('/api/v1/media/'.$speech.'/hosted/task/complete', [
            'mime' => 'audio/wav', 'data' => base64_encode('<script>not audio</script>'),
        ])->assertStatus(422);
        $wav = 'RIFF'.pack('V', 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', 0);
        $this->postJson('/api/v1/media/'.$speech.'/hosted/task/complete', [
            'mime' => 'audio/wav', 'data' => base64_encode($wav),
        ])->assertOk()->assertJsonPath('output_mime', 'audio/wav');
        $this->get('/api/v1/media/'.$speech.'/output')->assertOk()
            ->assertHeader('Content-Type', 'audio/wav');

        $doc = $this->postJson('/api/v1/media/hosted/task/begin', [
            'kind' => 'document_create', 'prompt' => 'A simple report',
        ])->assertStatus(202)->json('id');
        $this->postJson('/api/v1/media/'.$doc.'/hosted/task/complete', [
            'mime' => 'application/pdf', 'data' => base64_encode('<html>Not PDF</html>'),
        ])->assertStatus(422);
        $this->postJson('/api/v1/media/'.$doc.'/hosted/task/complete', [
            'mime' => 'application/pdf', 'data' => base64_encode("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
        ])->assertOk()->assertJsonPath('output_mime', 'application/pdf');
        $this->get('/api/v1/media/'.$doc.'/output')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_hosted_docx_saves_full_registered_mime_type_and_downloads_privately(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP ZIP is required for DOCX validation.');
        }
        $this->setupEngine();
        config(['models.frontend_chat_key' => str_repeat('s', 32)]);
        $this->withHeaders(['X-Chat-Bridge-Key' => str_repeat('s', 32)]);
        $id = $this->postJson('/api/v1/media/hosted/task/begin', [
            'kind' => 'document_create', 'prompt' => 'DOCX save regression',
        ])->assertStatus(202)->json('id');
        $tmp = tempnam(sys_get_temp_dir(), 'om-docx-test');
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($tmp, \ZipArchive::OVERWRITE));
            $zip->addFromString('[Content_Types].xml', '<Types/>');
            $zip->addFromString('word/document.xml', '<document>OMA-4729</document>');
            $zip->close();
            $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            $this->postJson('/api/v1/media/'.$id.'/hosted/task/complete', [
                'mime' => $mime, 'data' => base64_encode(file_get_contents($tmp)),
            ])->assertOk()->assertJsonPath('output_mime', $mime);
            $this->get('/api/v1/media/'.$id.'/output')->assertOk()->assertHeader('Content-Type', $mime);
            $this->assertDatabaseHas('media_tasks', ['id' => $id, 'status' => 'complete', 'output_mime' => $mime]);
        } finally {
            unlink($tmp);
        }
    }

    public function test_hosted_video_completion_requires_mp4_header_and_owner(): void
    {
        $this->setupEngine();
        config(['models.frontend_chat_key' => str_repeat('s', 32)]);
        $this->withHeaders(['X-Chat-Bridge-Key' => str_repeat('s', 32)]);
        $id = $this->postJson('/api/v1/media/hosted/task/begin', [
            'kind' => 'video', 'prompt' => 'Robot on a workbench',
        ])->assertStatus(202)->json('id');
        $mp4 = pack('N', 16).'ftypisom'.pack('N', 0);
        $this->postJson('/api/v1/media/'.$id.'/hosted/task/complete', [
            'mime' => 'video/mp4', 'data' => base64_encode('fake'),
        ])->assertStatus(422);
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/media/'.$id.'/hosted/task/complete', [
            'mime' => 'video/mp4', 'data' => base64_encode($mp4),
        ])->assertNotFound();
        $this->postJson('/api/v1/media/'.$id.'/hosted/task/fail', [])->assertNotFound();
    }

    public function test_disabled_engine_is_not_advertised_or_queued(): void
    {
        $this->setupEngine();
        config(['media.url' => null]);
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('media', [])->assertJsonPath('image_generation', false);
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Sunrise'])->assertStatus(503);
        Queue::assertNothingPushed();
    }

    public function test_generation_is_queued_and_private_binary_output_is_downloadable(): void
    {
        $this->setupEngine();
        $r = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Sunrise'])->assertStatus(202)->assertJsonPath('status', 'queued')->assertJsonMissingPath('input_path');
        $id = $r->json('id');
        Queue::assertPushed(RunMediaTask::class, fn ($j) => $j->taskId === $id && $j->queue === 'media' && $j->connection === 'media');
        Http::fake(['*' => Http::response(['data' => base64_encode($this->png()), 'mime' => 'image/png'])]);
        (new RunMediaTask($id))->handle();
        $this->getJson('/api/v1/media/'.$id)->assertOk()->assertJsonPath('status', 'complete')->assertJsonMissingPath('output_path');
        $output = $this->get('/api/v1/media/'.$id.'/output')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertEquals($this->png(), $output->streamedContent());
        Http::assertSent(fn ($r) => $r->url() === 'http://local-engine:8090/run' && $r->hasHeader('Authorization', 'Bearer '.str_repeat('x', 32)));
    }

    public function test_vision_input_is_validated_used_and_erased_after_processing(): void
    {
        $this->setupEngine();
        $this->postJson('/api/v1/media', ['kind' => 'vision', 'prompt' => 'Describe', 'input' => base64_encode('<?php evil ?>')])->assertStatus(422);
        $r = $this->postJson('/api/v1/media', ['kind' => 'vision', 'prompt' => 'Describe', 'input' => base64_encode($this->png())])->assertStatus(202);
        $task = MediaTask::findOrFail($r->json('id'));
        $input = $task->input_path;
        Storage::disk('local')->assertExists($input);
        Http::fake(['*' => Http::response(['text' => 'A white pixel'])]);
        (new RunMediaTask($task->id))->handle();
        $this->assertSame('A white pixel', $task->fresh()->result_text);
        $this->assertNull($task->fresh()->input_path);
        Storage::disk('local')->assertMissing($input);
        Http::assertSent(fn ($r) => $r['input'] === base64_encode($this->png()));
    }

    public function test_other_users_cannot_read_delete_or_download_a_task(): void
    {
        $this->setupEngine();
        $id = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Secret'])->json('id');
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/media/'.$id)->assertNotFound();
        $this->getJson('/api/v1/media/'.$id.'/output')->assertNotFound();
        $this->deleteJson('/api/v1/media/'.$id)->assertNotFound();
        $this->getJson('/api/v1/media')->assertJsonCount(0, 'data');
    }

    public function test_runtime_failure_is_recorded_without_leaking_runtime_secrets(): void
    {
        $this->setupEngine();
        $id = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Test'])->json('id');
        Http::fake(['*' => Http::response('SECRET trace', 500)]);
        (new RunMediaTask($id))->handle();
        $this->getJson('/api/v1/media/'.$id)->assertJsonPath('status', 'failed')->assertDontSee('SECRET');
        $this->getJson('/api/v1/media/'.$id.'/output')->assertNotFound();
    }

    public function test_malformed_runtime_binary_is_rejected(): void
    {
        $this->setupEngine();
        $id = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Test'])->json('id');
        Http::fake(['*' => Http::response(['data' => base64_encode('<script>evil</script>'), 'mime' => 'image/png'])]);
        (new RunMediaTask($id))->handle();
        $this->assertDatabaseHas('media_tasks', ['id' => $id, 'status' => 'failed', 'output_path' => null]);
    }

    public function test_deleted_running_task_discards_result(): void
    {
        $this->setupEngine();
        $id = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Test'])->json('id');
        Http::fake(function () use ($id) {
            MediaTask::find($id)->delete();

            return Http::response(['data' => base64_encode($this->png()), 'mime' => 'image/png']);
        });
        (new RunMediaTask($id))->handle();
        $this->assertDatabaseMissing('media_tasks', ['id' => $id]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_image_daily_limit_counts_one_job_and_does_not_interrupt_accepted_work(): void
    {
        $user = $this->setupEngine();
        config(['media.image_daily_limit' => 1]);
        $id = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Sunrise'])->assertStatus(202)->json('id');
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Another'])->assertStatus(429);
        Http::fake(['*' => Http::response(['data' => base64_encode($this->png()), 'mime' => 'image/png'])]);
        (new RunMediaTask($id))->handle();
        (new RunMediaTask($id))->handle();
        Http::assertSentCount(1);
        $this->getJson('/api/v1/media/'.$id)->assertOk()->assertJsonPath('status', 'complete');
        $this->assertDatabaseHas('media_daily_usage', ['user_id' => $user->id, 'kind' => 'image', 'requests' => 1]);
        $this->deleteJson('/api/v1/media/'.$id)->assertNoContent();
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Deleted result cannot refund quota'])->assertStatus(429);
        $this->travel(1)->days();
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Next day'])->assertStatus(202);
    }

    public function test_image_and_other_media_have_separate_user_daily_quotas(): void
    {
        $this->setupEngine();
        config(['media.image_daily_limit' => 1, 'media.daily_limit' => 1]);
        $this->postJson('/api/v1/media', ['kind' => 'speech', 'prompt' => 'Hello'])->assertStatus(202);
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Landscape'])->assertStatus(202);
        MediaTask::query()->update(['status' => 'complete']);
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Over image quota'])->assertStatus(429);
        $this->postJson('/api/v1/media', ['kind' => 'speech', 'prompt' => 'Over media quota'])->assertStatus(429);
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Other user'])->assertStatus(202);
    }

    public function test_pending_task_limit_and_quota_are_enforced(): void
    {
        $this->setupEngine();
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'One'])->assertStatus(202);
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Two'])->assertStatus(202);
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Three'])->assertStatus(429);
        MediaTask::query()->update(['status' => 'complete']);
        config(['media.storage_bytes' => 100]);
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Large'])->assertStatus(422);
    }

    public function test_suspended_users_queued_tasks_do_not_contact_runtime(): void
    {
        $user = $this->setupEngine();
        $id = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Test'])->json('id');
        $user->forceFill(['is_active' => false])->save();
        Http::fake();
        (new RunMediaTask($id))->handle();
        Http::assertNothingSent();
        $this->assertDatabaseHas('media_tasks', ['id' => $id, 'status' => 'failed']);
    }

    public function test_account_deletion_cleans_private_media(): void
    {
        $user = $this->setupEngine();
        Storage::disk('local')->put('media/test.output', 'private');
        MediaTask::create(['user_id' => $user->id, 'kind' => 'image', 'prompt' => 'Private', 'output_path' => 'media/test.output']);
        $this->deleteJson('/api/v1/me', ['password' => 'StrongPassword123'])->assertNoContent();
        Storage::disk('local')->assertMissing('media/test.output');
        $this->assertDatabaseCount('media_tasks', 0);
    }

    public function test_code_artifacts_are_persisted_but_not_in_list_payloads(): void
    {
        $this->setupEngine();
        config(['media.kinds' => ['code']]);
        $id = $this->postJson('/api/v1/media', ['kind' => 'code', 'prompt' => 'print(1)', 'approved' => true])->assertStatus(202)->json('id');
        Http::fake(['*' => Http::response(['text' => 'hello', 'mime' => 'application/zip', 'data' => 'UEsDBBQAAAAIAAEUVV2GphA2BwAAAAUAAAAKAAAAc3Rkb3V0LnR4dMtIzcnJBwBQSwECFAMUAAAACAABFFVdhqYQNgcAAAAFAAAACgAAAAAAAAAAAAAAgAEAAAAAc3Rkb3V0LnR4dFBLBQYAAAAAAQABADgAAAAvAAAAAAA=', 'artifacts' => [['name' => 'data.csv', 'type' => 'table', 'rows' => [['x', 'y'], ['1', '2']]]]])]);
        (new RunMediaTask($id))->handle();
        $this->getJson('/api/v1/media/'.$id)->assertOk()->assertJsonPath('status', 'complete')->assertJsonPath('artifacts.0.rows.1.1', '2');
        $this->getJson('/api/v1/media')->assertOk()->assertJsonMissingPath('data.0.artifacts');
    }
}
