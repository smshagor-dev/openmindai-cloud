<?php

namespace App\Jobs\Research;

use App\Models\Research\Run;
use App\Models\User;
use App\Services\Research\Access;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EmailRunStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(public int $runId, public int $generation, public string $status) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->runId))->releaseAfter(60)->expireAfter(180)];
    }

    public function handle(): void
    {
        $run = Run::find($this->runId);
        if (! $run || $run->generation !== $this->generation || $run->status !== $this->status ||
            $run->emailed_generation === $this->generation) {
            return;
        }

        $user = User::find($run->actor_id);
        if (! $user?->is_active || ! $user->email_verified_at) {
            return;
        }

        try {
            app(Access::class)->project($run->project_id, $user->id);
        } catch (HttpExceptionInterface $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                return;
            }

            throw $exception;
        }

        $completed = $this->status === 'completed';
        $subject = $completed ? 'Research Studio task completed' : 'Research Studio task failed';
        $body = $completed
            ? 'Your research task finished successfully. Open Research Studio to review the result.'
            : 'Your research task failed. Open Research Studio to review the activity and resume the run.';
        $base = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
        $url = $base.'/research-studio?project='.$run->project_id;

        Mail::raw($body."\n\n".$url, fn ($message) => $message->to($user->email)->subject($subject));

        Run::whereKey($run->id)->where('generation', $this->generation)->update(['emailed_generation' => $this->generation]);
    }
}
