<?php

namespace Tests\Feature;

use App\Http\Controllers\OpenMindAiGatewayController;
use App\Models\User;
use App\Services\StudioScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->mock(OpenMindAiGatewayController::class)->shouldReceive('mobileChat')->twice()->andReturn(response()->json(['choices' => [['message' => ['content' => '{"title":"Invalid"}']]] ]));
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/3d-scenes', ['mode' => 'architecture', 'prompt' => 'A web architecture'])->assertStatus(502);
        }
        $this->assertDatabaseCount('studio_scenes', 0);
    }

    public function test_invalid_prompt_is_rejected_before_inference(): void
    {
        Sanctum::actingAs($this->user(), ['user']);
        $this->mock(OpenMindAiGatewayController::class)->shouldNotReceive('mobileChat');
        $this->postJson('/api/v1/3d-scenes', ['mode' => 'script', 'prompt' => 'abc'])->assertUnprocessable();
    }

    public function test_scene_rejects_dangling_edges_and_executable_fields(): void
    {
        $scene = $this->scene();
        $scene['edges'] = [['from' => 'body', 'to' => 'missing', 'label' => 'bad']];
        try {
            app(StudioScene::class)->validate($scene);
            $this->fail('Dangling connection accepted.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('scene', $e->errors());
        }
        $scene = $this->scene();
        $scene['nodes'][0]['script'] = 'alert(1)';
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(StudioScene::class)->validate($scene);
    }
}
