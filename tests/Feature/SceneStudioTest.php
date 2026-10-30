<?php

namespace Tests\Feature;

use App\Http\Controllers\OpenMindAiGatewayController;
use App\Models\User;
use App\Services\StudioScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SceneStudioTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $u = User::create(['name' => 'Studio', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
        $u->forceFill(['email_verified_at' => now()])->save();

        return $u;
    }

    private function scene(): array
    {
        return ['title' => 'Robot', 'description' => 'A procedural robot.', 'nodes' => [['id' => 'body', 'label' => 'Body', 'shape' => 'box', 'position' => [0, 1, 0], 'rotation' => [0, 0, 0], 'scale' => [2, 1, 1], 'color' => '#4488ff']], 'edges' => []];
    }

    public function test_authentication_is_required(): void
    {
        $this->getJson('/api/v1/3d-scenes')->assertUnauthorized();
        $this->postJson('/api/v1/3d-scenes', ['mode' => 'object', 'prompt' => 'A robot'])->assertUnauthorized();
    }

    public function test_generation_persists_validated_scene_and_is_private(): void
    {
        $a = $this->user();
        Sanctum::actingAs($a, ['user']);
        $scene = $this->scene();
        $this->mock(OpenMindAiGatewayController::class)->shouldReceive('mobileChat')->once()->andReturn(response()->json(['choices' => [['message' => ['content' => json_encode($scene)]]]]));
        $id = $this->postJson('/api/v1/3d-scenes', ['mode' => 'object', 'prompt' => 'A robot with wheels'])->assertCreated()->assertJsonPath('scene.title', 'Robot')->json('id');
        $this->getJson('/api/v1/3d-scenes')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/3d-scenes/'.$id)->assertOk()->assertJsonPath('scene.nodes.0.id', 'body');
        Sanctum::actingAs($this->user(), ['user']);
        $this->getJson('/api/v1/3d-scenes')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/3d-scenes/'.$id)->assertNotFound();
        $this->deleteJson('/api/v1/3d-scenes/'.$id)->assertNotFound();
        Sanctum::actingAs($a, ['user']);
        $this->deleteJson('/api/v1/3d-scenes/'.$id)->assertNoContent();
        $this->getJson('/api/v1/3d-scenes/'.$id)->assertNotFound();
    }

    public function test_invalid_provider_output_is_not_saved_and_lock_is_released(): void
    {
        Sanctum::actingAs($this->user(), ['user']);
        $this->mock(OpenMindAiGatewayController::class)->shouldReceive('mobileChat')->twice()->andReturn(response()->json(['choices' => [['message' => ['content' => '{"title":"Invalid"}']]]]));
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/3d-scenes', ['mode' => 'architecture', 'prompt' => 'A web architecture'])->assertStatus(502);
        }
        $this->assertDatabaseCount('studio_scenes', 0);
    }

    public function test_real_gateway_contract_uses_ultra_and_accounts_for_credits(): void
    {
        config(['models.nvidia_key' => 'fixture-provider-secret']);
        $scene = $this->scene();
        Http::fake(['integrate.api.nvidia.com/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($scene)], 'finish_reason' => 'stop']]])]);
        $user = $this->user();
        $token = $user->createToken('Studio session', ['user'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/3d-scenes', ['mode' => 'architecture', 'prompt' => 'An application service'])->assertCreated();
        Http::assertSent(fn ($request) => $request['model'] === 'nvidia/nemotron-3-ultra-550b-a55b' && $request['stream'] === false && $request['chat_template_kwargs'] === ['enable_thinking' => false]);
        $this->assertDatabaseHas('api_credit_usage', ['user_id' => $user->id, 'credits' => 1]);
    }

    public function test_invalid_prompt_is_rejected_before_inference(): void
    {
        Sanctum::actingAs($this->user(), ['user']);
        $this->mock(OpenMindAiGatewayController::class)->shouldNotReceive('mobileChat');
        $this->postJson('/api/v1/3d-scenes', ['mode' => 'script', 'prompt' => 'abc'])->assertUnprocessable();
    }

    public function test_detailed_game_asset_output_is_repaired_instead_of_rejected(): void
    {
        Sanctum::actingAs($this->user(), ['user']);
        // Typical deviations for a long Unity/low-poly prompt: extra node fields,
        // thin parts below the scale minimum, shape synonyms, short colours,
        // numeric strings, dotted ids and an overlong description.
        $raw = ['title' => 'Futuristic Robot', 'description' => str_repeat('FBX, GLB and UV export notes. ', 120), 'nodes' => [
            ['id' => 'robot.torso', 'label' => 'Torso', 'shape' => 'cube', 'position' => [0, '1.2', 0], 'rotation' => [0, 0, 0], 'scale' => [0.8, 1, 0.5], 'color' => '#3af', 'material' => 'MetalBlue', 'parent' => null],
            ['id' => 'antenna', 'label' => 'Antenna', 'shape' => 'capsule', 'position' => [0, 2.4, 0], 'scale' => [0.02, 0.4, 0.02], 'color' => 'teal', 'pivot' => [0, 0, 0]],
            ['id' => 'robot.torso', 'label' => 'Head', 'shape' => 'sphere', 'position' => [0, 2, 0], 'rotation' => [0, 9, 0], 'scale' => [0.5, 0.5, 0.5], 'color' => '#ffffff'],
        ], 'edges' => [['from' => 'robot.torso', 'to' => 'nowhere', 'label' => 'joint']]];
        $this->mock(OpenMindAiGatewayController::class)->shouldReceive('mobileChat')->once()->andReturn(response()->json(['choices' => [['message' => ['content' => "```json\n".json_encode($raw)."\n```"]]]]));
        $scene = $this->postJson('/api/v1/3d-scenes', ['mode' => 'object', 'prompt' => 'Create a stylized low-poly futuristic robot for Unity with FBX and GLB exports.'])
            ->assertCreated()->json('scene');
        $this->assertSame(['robot-torso', 'antenna', 'robot-torso-2'], array_column($scene['nodes'], 'id'));
        $this->assertSame(['box', 'cylinder', 'sphere'], array_column($scene['nodes'], 'shape'));
        $this->assertSame('#33aaff', $scene['nodes'][0]['color']);
        $this->assertSame('#9aa4b2', $scene['nodes'][1]['color']);
        $this->assertSame([0.05, 0.4, 0.05], $scene['nodes'][1]['scale']);
        $this->assertEquals([0, 0, 0], $scene['nodes'][1]['rotation']);
        $this->assertEquals(6.284, $scene['nodes'][2]['rotation'][1]);
        $this->assertArrayNotHasKey('material', $scene['nodes'][0]);
        $this->assertSame(2000, mb_strlen($scene['description']));
        $this->assertSame([], $scene['edges']);
    }

    public function test_written_change_request_edits_the_open_scene_as_a_revision(): void
    {
        $owner = $this->user();
        Sanctum::actingAs($owner, ['user']);
        $original = $this->scene();
        $edited = $original;
        $edited['nodes'][0]['color'] = '#ff0000';
        $edited['nodes'][] = ['id' => 'antenna', 'label' => 'Antenna', 'shape' => 'cone', 'position' => [0, 2, 0], 'rotation' => [0, 0, 0], 'scale' => [0.1, 0.4, 0.1], 'color' => '#ffffff'];
        $sent = [];
        $this->mock(OpenMindAiGatewayController::class)->shouldReceive('mobileChat')->twice()
            ->andReturnUsing(function ($input) use (&$sent, $original, $edited) {
                $sent[] = $input->input('messages');

                return response()->json(['choices' => [['message' => ['content' => json_encode(count($sent) === 1 ? $original : $edited)]]]]);
            });
        $id = $this->postJson('/api/v1/3d-scenes', ['mode' => 'object', 'prompt' => 'A robot'])->assertCreated()->json('id');
        $this->postJson("/api/v1/3d-scenes/$id/edit", ['prompt' => 'Make the body red and add an antenna', 'version' => 1])
            ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('scene.nodes.0.color', '#ff0000')->assertJsonCount(2, 'scene.nodes');
        $payload = json_decode(collect($sent[1])->where('role', 'user')->pluck('content')->implode(''), true);
        $this->assertSame('Make the body red and add an antenna', $payload['requested_changes']);
        $this->assertSame('body', $payload['current_scene']['nodes'][0]['id']);
        $this->getJson("/api/v1/3d-scenes/$id/revisions")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.version', 1);
        // A stale version is rejected before any model call.
        $this->postJson("/api/v1/3d-scenes/$id/edit", ['prompt' => 'Make it blue', 'version' => 1])->assertStatus(409);
        Sanctum::actingAs($this->user(), ['user']);
        $this->postJson("/api/v1/3d-scenes/$id/edit", ['prompt' => 'Make it blue', 'version' => 2])->assertNotFound();
    }

    public function test_scene_rejects_dangling_edges_and_executable_fields(): void
    {
        $scene = $this->scene();
        $scene['edges'] = [['from' => 'body', 'to' => 'missing', 'label' => 'bad']];
        try {
            app(StudioScene::class)->validate($scene);
            $this->fail('Dangling connection accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('scene', $e->errors());
        }
        $scene = $this->scene();
        $scene['nodes'][0]['script'] = 'alert(1)';
        $this->expectException(ValidationException::class);
        app(StudioScene::class)->validate($scene);
    }
}
