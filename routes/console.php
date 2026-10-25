<?php

use App\Jobs\RunMediaTask;
use App\Jobs\RunWorkspaceTask;
use App\Models\ConversationShare;
use App\Models\MediaTask;
use App\Models\Message;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\AdminSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('admin:create {email} {name}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Account already exists.');

        return 1;
    }
    $password = $this->secret('Password (12+ characters, uppercase, lowercase and number)');
    if (! is_string($password) || strlen($password) < 12 || ! preg_match('/[A-Z]/', $password) || ! preg_match('/[a-z]/', $password) || ! preg_match('/[0-9]/', $password)) {
        $this->error('Password does not meet policy.');

        return 1;
    }
    $u = User::create(['name' => $this->argument('name'), 'email' => $email, 'password' => $password]);
    $u->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
    $this->info('Administrator created. Enable 2FA using the account API.');
})->purpose('Create an administrator without a default password');
Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::call(fn () => Message::where('status', 'generating')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'failed']))->everyMinute();
Schedule::call(fn () => ConversationShare::where('expires_at', '<', now())->delete())->daily();
Schedule::call(fn () => DB::table('team_invites')->where('expires_at', '<', now())->delete())->daily();

// A killed queue process cannot run its failure hook. Clean abandoned media inputs.
Schedule::call(function () {
    MediaTask::where(function ($q) {
        $q->where('status', 'running')->where('updated_at', '<', now()->subMinutes(20));
    })->orWhere(function ($q) {
        $q->where('status', 'queued')->where('created_at', '<', now()->subDay());
    })->eachById(fn ($task) => (new RunMediaTask($task->id))->failed(null));
})->everyFiveMinutes();

Schedule::call(function () {
    ScheduledTask::where('enabled', true)->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->select('id')->chunkById(100, function ($tasks) {
        foreach ($tasks as $task) {
            DB::transaction(function () use ($task) {
                $t = ScheduledTask::lockForUpdate()->find($task->id);
                if (! $t || ! $t->enabled || ! $t->next_run_at || $t->next_run_at->isFuture()) {
                    return;
                }
                $active = TaskRun::where('scheduled_task_id', $t->id)->whereIn('status', ['queued', 'running'])->exists();
                $daily = TaskRun::whereIn('scheduled_task_id', ScheduledTask::where('user_id', $t->user_id)->select('id'))->whereDate('created_at', today())->count();
                $dailyLimit = app(AdminSettings::class)->limit('task_daily_limit');
                if ($active) {
                    $t->update(['next_run_at' => now()->addMinutes(5)]);

                    return;
                }
                if ($dailyLimit > 0 && $daily >= $dailyLimit) {
                    $t->update(['next_run_at' => now()->addDay()->startOfDay()]);

                    return;
                }
                if (! $active && ($dailyLimit === 0 || $daily < $dailyLimit)) {
                    $run = TaskRun::create(['scheduled_task_id' => $t->id, 'steps' => [['label' => 'Scheduled run queued', 'status' => 'queued']]]);
                    RunWorkspaceTask::dispatch($run->id)->onQueue('tasks')->afterCommit();
                }
                $t->update(['enabled' => $t->frequency !== 'once', 'next_run_at' => match ($t->frequency) {
                    'daily' => now()->addDay(),'weekly' => now()->addWeek(),default => null
                }]);
            });
        }
    });
})->name('workspace-task-dispatch')->everyMinute()->withoutOverlapping();
Schedule::call(function () {
    TaskRun::where('status', 'running')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'failed', 'error' => 'Task worker stopped before completing.']);
    TaskRun::where('status', 'queued')->where('updated_at', '<', now()->subDay())->update(['status' => 'failed', 'error' => 'Task worker did not start in time.']);
})->everyFiveMinutes();

// Cosmos hosted media is private and short-lived. Remove expired output and
// clear orphaned state if a shared-hosting cron worker terminates unexpectedly.
Schedule::call(function () {
    DB::table('cosmos_generations')->where('status', 'running')
        ->where('updated_at', '<', now()->subMinutes(30))->update([
            'status' => 'failed', 'error_code' => 'worker_interrupted',
            'encrypted_request' => '', 'updated_at' => now(),
        ]);
    DB::table('cosmos_generations')->where('status', 'queued')
        ->where('created_at', '<', now()->subDay())->update([
            'status' => 'failed', 'error_code' => 'worker_not_started',
            'encrypted_request' => '', 'updated_at' => now(),
        ]);
    DB::table('cosmos_generations')->where('created_at', '<', now()->subHours(
        (int) config('cosmos.retention_hours', 24)
    ))->orderBy('created_at')->chunk(100, function ($items) {
        foreach ($items as $item) {
            if ($item->output_path) {
                Storage::disk('local')->delete($item->output_path);
            }
            DB::table('cosmos_generations')->where('id', $item->id)->delete();
        }
    });
})->hourly();

// NVIDIA Maxine media artifacts are private, short-lived and need cleanup
// even when a worker is terminated by a shared-hosting process limit.
Schedule::call(function () {
    DB::table('maxine_jobs')->where('status', 'running')
        ->where('updated_at', '<', now()->subMinutes(25))->update([
            'status' => 'failed', 'error_code' => 'worker_interrupted', 'updated_at' => now(),
        ]);
    DB::table('maxine_jobs')->where('status', 'queued')
        ->where('created_at', '<', now()->subDay())->update([
            'status' => 'failed', 'error_code' => 'worker_not_started', 'updated_at' => now(),
        ]);
    DB::table('maxine_jobs')->where('created_at', '<',
        now()->subHours((int) config('maxine.retention_hours', 24)))->orderBy('created_at')
        ->chunk(100, function ($items) {
            foreach ($items as $item) {
                Storage::disk('local')->delete(array_filter([$item->input_path, $item->aux_path, $item->output_path]));
                DB::table('maxine_jobs')->where('id', $item->id)->delete();
            }
        });
})->hourly();

// Remove expired private AV ZIP files and mark interrupted jobs failed.
Schedule::call(function () {
    DB::table('av_scene_jobs')->where('status', 'running')->where('updated_at', '<', now()->subMinutes(25))
        ->update(['status' => 'failed', 'error_code' => 'worker_interrupted', 'updated_at' => now()]);
    DB::table('av_scene_jobs')->where('status', 'queued')->where('created_at', '<', now()->subDay())
        ->update(['status' => 'failed', 'error_code' => 'worker_not_started', 'updated_at' => now()]);
    DB::table('av_scene_jobs')->where('created_at', '<', now()->subHours(24))->orderBy('created_at')
        ->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                if ($row->output_path) {
                    Storage::disk('local')->delete($row->output_path);
                }
                DB::table('av_scene_jobs')->where('id', $row->id)->delete();
            }
        });
})->hourly();

// Remove temporary Cosmos Transfer MP4 media and finish orphaned queue jobs.
Schedule::call(function () {
    DB::table('cosmos_transfer_jobs')->where('status', 'running')
        ->where('updated_at', '<', now()->subMinutes(25))
        ->update(['status' => 'failed', 'error_code' => 'worker_interrupted', 'updated_at' => now()]);
    DB::table('cosmos_transfer_jobs')->where('status', 'queued')
        ->where('created_at', '<', now()->subDay())
        ->update(['status' => 'failed', 'error_code' => 'worker_not_started', 'updated_at' => now()]);
    DB::table('cosmos_transfer_jobs')->where('created_at', '<', now()->subHours(24))->orderBy('created_at')
        ->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                Storage::disk('local')->delete(array_filter([
                    $row->input_path, $row->control_path, $row->output_path,
                ]));
                DB::table('cosmos_transfer_jobs')->where('id', $row->id)->delete();
            }
        });
})->hourly();

// Short retention for private text-to-speech prompts, reference WAVs and results.
Schedule::call(function () {
    DB::table('magpie_generations')->where('status', 'running')
        ->where('updated_at', '<', now()->subMinutes(25))
        ->update(['status' => 'failed', 'error_code' => 'worker_interrupted',
            'encrypted_request' => '', 'updated_at' => now()]);
    DB::table('magpie_generations')->where('status', 'queued')
        ->where('created_at', '<', now()->subDay())
        ->update(['status' => 'failed', 'error_code' => 'worker_not_started',
            'encrypted_request' => '', 'updated_at' => now()]);
    DB::table('magpie_generations')->where('created_at', '<',
        now()->subHours((int) config('magpie.retention_hours', 24)))
        ->orderBy('created_at')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                Storage::disk('local')->delete(array_filter([$row->voice_path, $row->output_path]));
                DB::table('magpie_generations')->where('id', $row->id)->delete();
            }
        });
})->hourly();

// Expired one-time realtime tickets cannot block a user's next session.
Schedule::call(function () {
    DB::table('voicechat_sessions')->where('status', 'issued')
        ->where('expires_at', '<', now())->update([
            'status' => 'expired', 'ended_at' => now(), 'updated_at' => now(),
        ]);
    DB::table('voicechat_sessions')->where('status', 'claimed')
        ->where('claimed_at', '<', now()->subMinutes(20))->update([
            'status' => 'closed', 'ended_at' => now(), 'updated_at' => now(),
        ]);
    DB::table('voicechat_sessions')->where('created_at', '<', now()->subDay())
        ->orderBy('created_at')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('voicechat_sessions')->where('id', $row->id)->delete();
            }
        });
})->hourly();

Artisan::command('research:recover', function(){ app(\App\Services\Research\Orchestrator::class)->recover(); $this->info('Research leases checked.'); });
Schedule::command('research:recover')->everyMinute()->withoutOverlapping();
