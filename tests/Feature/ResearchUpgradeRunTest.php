<?php

namespace Tests\Feature;

use App\Models\Research\Project;
use App\Models\Research\Task;
use App\Models\User;
use App\Services\Research\Orchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResearchUpgradeRunTest extends TestCase
{
    use RefreshDatabase;

    private function paused(): array
    {
        Queue::fake();
        $user = User::create(['name' => 'Upgrade fixture', 'email' => 'upgrade@example.test', 'password' => 'SyntheticOnly123']);
        $project = Project::create(['owner_id' => $user->id, 'title' => 'Saved project', 'prompt' => 'Retain existing evidence', 'settings' => []]);
        $run = app(Orchestrator::class)->start($project, $user->id, 'literature');
        app(Orchestrator::class)->control($project, 'pause');
        $task = Task::where('run_id', $run->id)->sole();
        $old = DB::table('research_agent_definitions')->where('id', $task->definition_id)->first();
        $oldId = DB::table('research_agent_definitions')->insertGetId(['key' => 'literature', 'version' => str_repeat('a', 64), 'definition' => $old->definition, 'created_at' => now(), 'updated_at' => now()]);
        $task->update(['definition_id' => $oldId, 'checkpoint' => ['index' => 36, 'analyses' => [['Retained Unicode বাংলা']]], 'errors' => 1]);

        return [$project, $run->fresh(), $task->fresh()];
    }

    public function test_reviewed_upgrade_preserves_checkpoint_errors_and_paused_state_and_audits_changes(): void
    {
        [$project, $run, $task] = $this->paused();
        $this->assertSame(0, Artisan::call('research:upgrade-run', ['run' => $run->id]));
        $plan = json_decode(Artisan::output(), true);
        $this->assertFalse($plan['applied']);
        $this->assertSame($task->definition_id, $task->fresh()->definition_id);
        $this->assertSame(0, Artisan::call('research:upgrade-run', ['run' => $run->id, '--apply' => $plan['plan_hash']]));
        $updated = $task->fresh();
        $this->assertNotEquals($task->definition_id, $updated->definition_id);
        $this->assertEquals($task->checkpoint, $updated->checkpoint);
        $this->assertSame(1, $updated->errors);
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertSame($project->fresh()->generation, $run->generation + 1);
        $this->assertSame(1, DB::table('research_events')->where('type', 'task.implementation_upgraded')->count());
    }

    public function test_stale_plan_cannot_upgrade_changed_checkpoints(): void
    {
        [, $run, $task] = $this->paused();
        Artisan::call('research:upgrade-run', ['run' => $run->id]);
        $plan = json_decode(Artisan::output(), true);
        $task->update(['checkpoint' => ['index' => 37]]);
        $this->assertSame(1, Artisan::call('research:upgrade-run', ['run' => $run->id, '--apply' => $plan['plan_hash']]));
        $this->assertSame($task->definition_id, $task->fresh()->definition_id);
    }

    public function test_changed_contract_or_running_run_is_refused(): void
    {
        [$project, $run, $task] = $this->paused();
        DB::table('research_agent_definitions')->where('id', $task->definition_id)->update(['definition' => json_encode(['tools' => ['different_tool']])]);
        $this->assertSame(1, Artisan::call('research:upgrade-run', ['run' => $run->id]));
        $run->update(['status' => 'running']);
        $this->assertSame(1, Artisan::call('research:upgrade-run', ['run' => $run->id]));
        $this->assertSame(0, DB::table('research_events')->where('type', 'task.implementation_upgraded')->count());
    }
}
