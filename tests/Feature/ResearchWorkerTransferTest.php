<?php

namespace Tests\Feature;

use App\Services\Research\ExecutionProvenance;
use App\Services\Research\Worker;
use App\Services\Research\WorkerTransfers;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchWorkerTransferTest extends TestCase
{
    private string $privateStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateStorage = sys_get_temp_dir().'/research-worker-test-'.bin2hex(random_bytes(10));
        $this->app->useStoragePath($this->privateStorage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->privateStorage);
        parent::tearDown();
    }

    public function test_transfers_require_scoped_ticket_correct_sequence_and_one_result_only(): void
    {
        $service = app(WorkerTransfers::class);
        $t = $service->create('extract', ['data' => 'controlled']);
        $base = '/api/v1/internal/research-worker/transfers/'.$t['id'];
        $this->getJson($base.'/input')->assertUnauthorized();
        $this->withToken($t['ticket'])->postJson($base.'/authorize')->assertConflict();
        $this->withToken($t['ticket'])->getJson($base.'/input')->assertOk()->assertJsonPath('payload.data', 'controlled');
        $this->withToken($t['ticket'])->getJson($base.'/input')->assertConflict();
        $this->withToken($t['ticket'])->postJson($base.'/authorize')->assertOk();
        $this->withToken($t['ticket'])->postJson($base.'/result', ['text' => 'actual callback'])->assertOk();
        $this->withToken($t['ticket'])->postJson($base.'/result', ['text' => 'replayed'])->assertConflict();
        $this->assertSame(['text' => 'actual callback'], $service->result($t['id']));
    }

    public function test_cancelled_execution_cannot_authorize_a_delayed_submission(): void
    {
        $service = app(WorkerTransfers::class);
        $id = 'research-'.str_repeat('d', 32);
        $t = $service->create('experiment', ['execution_id' => $id]);
        $base = '/api/v1/internal/research-worker/transfers/'.$t['id'];
        $this->withToken($t['ticket'])->getJson($base.'/input')->assertOk();
        $service->cancel($id);
        $this->withToken($t['ticket'])->postJson($base.'/authorize')->assertConflict();
        $this->withToken($t['ticket'])->postJson($base.'/result', ['exit_code' => 0])->assertConflict();
    }

    public function test_large_payload_uses_small_dispatch_and_private_callback_then_cleans_up(): void
    {
        config(['research.worker_transport' => 'vercel', 'research.worker_url' => 'https://worker.example/api/research-worker', 'research.worker_key' => str_repeat('k', 40)]);
        Http::preventStrayRequests();
        Http::fake(['worker.example/*' => function ($request) {
            $t = $request->data();
            $this->assertLessThan(2048, strlen($request->body()));
            $service = app(WorkerTransfers::class);
            $input = json_decode($service->access($t['id'], $t['ticket'], 'input'), true);
            $this->assertSame(5000000, strlen($input['payload']['data']));
            $service->access($t['id'], $t['ticket'], 'authorize');
            $service->access($t['id'], $t['ticket'], 'result', '{"text":"callback output"}');

            return Http::response(['stored' => true]);
        }]);
        $this->assertSame(['text' => 'callback output'], app(Worker::class)->call('extract', ['data' => str_repeat('x', 5000000)]));
        $this->assertCount(0, File::files(storage_path('app/private/research-worker-transfers')));
    }

    public function test_expired_transfers_fail_closed_and_worker_failure_removes_private_payload(): void
    {
        $service = app(WorkerTransfers::class);
        $t = $service->create('extract', []);
        $path = storage_path('app/private/research-worker-transfers/'.$t['id'].'.meta');
        $meta = json_decode(File::get($path), true);
        $meta['expires'] = 0;
        File::put($path, json_encode($meta));
        $this->withToken($t['ticket'])->getJson('/api/v1/internal/research-worker/transfers/'.$t['id'].'/input')->assertGone();
        $service->remove($t['id']);
        config(['research.worker_transport' => 'vercel', 'research.worker_url' => 'https://worker.example', 'research.worker_key' => str_repeat('k', 40)]);
        Http::fake(['worker.example/*' => Http::response([], 503)]);
        try {
            app(Worker::class)->call('extract', []);
            $this->fail('Worker failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('research_worker_failed', $e->getMessage());
        }
        $this->assertCount(0, File::files(storage_path('app/private/research-worker-transfers')));
    }

    public function test_microvm_provenance_requires_the_configured_immutable_snapshot_and_runtime(): void
    {
        config(['research.worker_snapshot' => 'snap_test123', 'research.worker_runtime_digest' => 'sha256:'.str_repeat('a', 64)]);
        $p = ['isolation' => 'firecracker_microvm', 'snapshot_id' => 'snap_test123', 'runtime_digest' => 'sha256:'.str_repeat('a', 64), 'network' => 'none'];
        $this->assertTrue(ExecutionProvenance::runtime($p));
        $this->assertFalse(ExecutionProvenance::runtime(array_replace($p, ['snapshot_id' => 'snap_foreign'])));
        $this->assertFalse(ExecutionProvenance::runtime(array_replace($p, ['network' => 'allow-all'])));
        $this->assertTrue(ExecutionProvenance::runtime(['image' => 'sha256:'.str_repeat('b', 64)]));
    }
}
