<?php

namespace Tests\Feature;

use App\Jobs\Research\ExportDocument;
use App\Jobs\Research\NotifyDelivery;
use App\Models\Research\Document;
use App\Models\Research\Export;
use App\Models\Research\Finding;
use App\Models\Research\Project;
use App\Models\Research\Run;
use App\Models\User;
use App\Services\Research\Deliveries;
use App\Services\Research\Orchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResearchDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        $user = User::create(['name' => 'Delivery fixture', 'email' => uniqid().'@example.test', 'password' => 'StrongPassword123', 'email_verified_at' => now()]);
        Sanctum::actingAs($user, ['user']);
        $p = Project::create(['owner_id' => $user->id, 'title' => 'Delivery fixture', 'prompt' => 'Review source evidence', 'settings' => []]);
        $p->refresh();
        Document::create(['project_id' => $p->id, 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]);
        $run = Run::create(['project_id' => $p->id, 'actor_id' => $user->id, 'kind' => 'workflow', 'status' => 'completed', 'generation' => $p->generation]);
        $content = ['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Generated proposal']]], ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'This is a draft research proposal requiring human review. No experiments were performed.']]]]];
        $task = app(Orchestrator::class)->task($run, 'manuscript', 'manuscript');
        Finding::create(['project_id' => $p->id, 'task_id' => $task->id, 'kind' => 'manuscript', 'data' => ['content' => $content, 'base_version' => 1]]);

        return [$p, $run, $user];
    }

    public function test_completion_delivery_queues_five_immutable_proposal_exports_once_without_overwriting_saved_document(): void
    {
        [$p,$run] = $this->fixture();
        $first = app(Deliveries::class)->generate($run->id);
        $second = app(Deliveries::class)->generate($run->id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('proposal', $first->content_source);
        $this->assertTrue($first->draft);
        $this->assertSame(['bib', 'docx', 'md', 'pdf', 'tex'], Export::where('delivery_id', $first->id)->orderBy('format')->pluck('format')->all());
        Queue::assertPushed(ExportDocument::class, 5);
        $this->assertSame(1, Document::where('project_id', $p->id)->value('version'));
        $this->assertSame(['type' => 'doc', 'content' => [['type' => 'paragraph']]], Document::where('project_id', $p->id)->value('content'));
        foreach (Export::where('delivery_id', $first->id)->get() as $e) {
            $this->assertTrue($e->draft);
            $this->assertStringContainsString('Generated proposal', json_encode($e->snapshot['document']));
        }
    }

    public function test_partial_delivery_notifies_once_and_retry_only_requeues_failed_formats(): void
    {
        [$p,$run] = $this->fixture();
        $delivery = app(Deliveries::class)->generate($run->id);
        Export::where('delivery_id', $delivery->id)->update(['status' => 'completed']);
        Export::where('delivery_id', $delivery->id)->where('format', 'pdf')->update(['status' => 'failed']);
        app(Deliveries::class)->settle($delivery->id);
        app(Deliveries::class)->settle($delivery->id);
        $this->assertSame('partial', $delivery->fresh()->status);
        Queue::assertPushed(NotifyDelivery::class, 1);
        Queue::fake();
        app(Deliveries::class)->retry($delivery->id);
        Queue::assertPushed(ExportDocument::class, 1);
        $this->assertSame(4, Export::where('delivery_id', $delivery->id)->where('status', 'completed')->count());
    }

    public function test_other_accounts_cannot_generate_retry_or_download_project_files(): void
    {
        [$p,$run] = $this->fixture();
        $d = app(Deliveries::class)->generate($run->id);
        $file = Export::where('delivery_id', $d->id)->first();
        Sanctum::actingAs(User::create(['name' => 'Delivery fixture', 'email' => uniqid().'@example.test', 'password' => 'StrongPassword123', 'email_verified_at' => now()]), ['user']);
        $this->postJson("/api/v1/research/projects/$p->id/deliveries")->assertNotFound();
        $this->postJson("/api/v1/research/projects/$p->id/deliveries/$d->id/retry")->assertNotFound();
        $this->getJson("/api/v1/research/projects/$p->id/exports/$file->id/download")->assertNotFound();
    }

    public function test_push_subscriptions_require_auth_and_reject_private_network_endpoints(): void
    {
        [$p,$run,$u] = $this->fixture();
        config(['webpush.public_key' => 'fixture', 'webpush.private_key' => 'fixture']);
        $keys = ['p256dh' => rtrim(strtr(base64_encode(str_repeat('x', 65)), '+/', '-_'), '='), 'auth' => rtrim(strtr(base64_encode(str_repeat('y', 16)), '+/', '-_'), '=')];
        foreach (['https://127.0.0.1/send', 'https://fcm.googleapis.com.evil.example/send', 'https://user:pass@fcm.googleapis.com/send', 'https://fcm.googleapis.com:444/send'] as $url) {
            $this->postJson('/api/v1/research/push/subscriptions', ['endpoint' => $url, 'keys' => $keys])->assertStatus(422);
        }
        $this->postJson('/api/v1/research/push/subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/fixture-device', 'keys' => $keys])->assertOk();
        $row = DB::table('web_push_subscriptions')->first();
        $this->assertNotSame($keys['auth'], $row->auth_token);
        $this->assertSame($keys['auth'], decrypt($row->auth_token));
        $this->deleteJson('/api/v1/research/push/subscriptions', ['endpoint' => $row->endpoint])->assertOk();
        $this->assertSame(0, DB::table('web_push_subscriptions')->count());
    }
}
