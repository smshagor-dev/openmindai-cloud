<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioUploadTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $user = User::create(['name' => 'Studio upload', 'email' => uniqid().'@example.com', 'password' => 'SyntheticPassword123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user, ['user']);

        return $user;
    }

    private function scene(): array
    {
        return ['title' => 'Reference robot', 'description' => 'Based on supplied reference.', 'nodes' => [['id' => 'body', 'label' => 'Blue body', 'shape' => 'box', 'position' => [0, 1, 0], 'rotation' => [0, 0, 0], 'scale' => [2, 1, 1], 'color' => '#4488ff']], 'edges' => []];
    }

    public function test_private_original_is_preserved_downloadable_and_scoped(): void
    {
        $owner = $this->actor();
        $text = '# Robot reference বাংলা';
        $id = $this->postJson('/api/v1/3d-scenes/uploads', ['name' => 'robot.md', 'data' => base64_encode($text), 'text' => $text])->assertCreated()->assertJsonMissingPath('private_path')->json('id');
        $this->getJson('/api/v1/3d-scenes/uploads')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.private_path');
        $this->get('/api/v1/3d-scenes/uploads/'.$id)->assertOk()->assertContent($text)->assertHeader('X-Content-Type-Options', 'nosniff');
        $file = DB::table('studio_uploads')->find($id);
        Storage::disk('local')->assertExists($file->private_path);
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'SyntheticPassword123']);
        Sanctum::actingAs($other, ['user']);
        $this->getJson('/api/v1/3d-scenes/uploads/'.$id)->assertNotFound();
        $this->postJson('/api/v1/3d-scenes', ['mode' => 'object', 'prompt' => 'Use my reference', 'upload_ids' => [$id]])->assertNotFound();
        Http::assertNothingSent();
        $this->assertSame($owner->id, $file->user_id);
    }

    public function test_documents_are_untrusted_context_and_remain_linked_to_saved_scene(): void
    {
        $this->actor();
        $id = $this->postJson('/api/v1/3d-scenes/uploads', ['name' => 'reference.md', 'data' => base64_encode('Blue body'), 'text' => 'Blue body'])->assertCreated()->json('id');
        $scene = $this->scene();
        $this->mock(\App\Http\Controllers\OpenMindAiGatewayController::class)->shouldReceive('mobileChat')->once()->andReturnUsing(function ($request) use ($scene) {
            $this->assertStringContainsString('untrusted', $request->input('messages.0.content'));
            $this->assertStringContainsString('Blue body', $request->input('messages.1.content'));

            return response()->json(['choices' => [['message' => ['content' => json_encode($scene)]]]]);
        });
        $saved = $this->postJson('/api/v1/3d-scenes', ['mode' => 'object', 'prompt' => 'Use my reference', 'upload_ids' => [$id]])->assertCreated()->assertJsonPath('upload_ids.0', $id)->json('id');
        $this->getJson('/api/v1/3d-scenes/'.$saved)->assertOk()->assertJsonPath('upload_ids.0', $id);
    }

    public function test_invalid_uploads_are_rejected_without_storage(): void
    {
        $this->actor();
        foreach ([['name' => 'payload.php', 'data' => base64_encode('<?php echo 1;'), 'text' => 'test'], ['name' => 'fake.pdf', 'data' => base64_encode('not a PDF'), 'text' => 'test'], ['name' => 'bad.png', 'data' => base64_encode('<svg/>'), 'image_preview' => 'data:image/svg+xml;base64,PHN2Zy8+'], ['name' => 'empty.md', 'data' => base64_encode('document'), 'text' => '']] as $input) {
            $this->postJson('/api/v1/3d-scenes/uploads', $input)->assertUnprocessable();
        }
        $this->assertDatabaseCount('studio_uploads', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_image_reference_uses_real_vision_gateway_before_scene_model(): void
    {
        $this->actor();
        config(['models.nvidia_key' => 'synthetic-provider-secret']);
        DB::table('hosted_code_agents')->where('slug', 'nvidia-llama-3.2-90b-vision-instruct')->update(['enabled' => true, 'api_ready' => true, 'last_test_status' => 'success']);
        $image = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV7sAAAAASUVORK5CYII=';
        $id = $this->postJson('/api/v1/3d-scenes/uploads', ['name' => 'reference.png', 'data' => $image, 'image_preview' => 'data:image/png;base64,'.$image])->assertCreated()->json('id');
        Http::fake(['integrate.api.nvidia.com/*' => Http::sequence()->push(['choices' => [['message' => ['content' => 'A blue rectangular robot body.'], 'finish_reason' => 'stop']]])->push(['choices' => [['message' => ['content' => json_encode($this->scene())], 'finish_reason' => 'stop']]])]);
        $this->postJson('/api/v1/3d-scenes', ['mode' => 'object', 'prompt' => 'Use the image reference', 'upload_ids' => [$id]])->assertCreated();
        Http::assertSentCount(2);
        $sent = Http::recorded();
        $this->assertSame('meta/llama-3.2-90b-vision-instruct', $sent[0][0]['model']);
        $this->assertStringContainsString('A blue rectangular robot body.', json_encode($sent[1][0]['messages']));
        $this->assertSame('nvidia/nemotron-3-ultra-550b-a55b', $sent[1][0]['model']);
    }
}
