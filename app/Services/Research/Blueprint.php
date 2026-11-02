<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

class Blueprint
{
    public const COMMIT = 'a6d9efdec69a3052e1ee7f15a45d25711ac05d2a';

    public function configured(): bool
    {
        return is_executable((string) config('research.blueprint_python')) && is_dir((string) config('research.blueprint_root'));
    }

    private function process(array $args, array $extra = []): Process
    {
        $env = array_fill_keys(array_unique(array_merge(array_keys(getenv() ?: []), array_keys($_ENV))), false);
        $env = array_merge($env, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => config('research.blueprint_root'), 'PYTHONNOUSERSITE' => '1', 'PYTHONUTF8' => '1', 'OPENMIND_BLUEPRINT_ROOT' => config('research.blueprint_root'), 'OPENBLAS_NUM_THREADS' => '1', 'OMP_NUM_THREADS' => '1', 'RAYON_NUM_THREADS' => '1', 'TOKIO_WORKER_THREADS' => '1', 'TOKENIZERS_PARALLELISM' => 'false'], $extra);

        return new Process(array_merge([(string) config('research.blueprint_python'), base_path('runtime/nvidia-blueprint/runner.py')], $args), config('research.blueprint_root'), $env);
    }

    private function result(Process $process): array
    {
        $lines = explode("\n", $process->getOutput());
        $last = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'OPENMIND_RESULT ')) {
                $last = json_decode(substr($line, 16), true);
            }
        }
        if (! $process->isSuccessful() || ! is_array($last) || isset($last['error_code'])) {
            $frames = collect($last['exception_frames'] ?? [])->take(8)->map(fn ($f) => ['file' => preg_replace('/[^a-zA-Z0-9_.-]/', '', basename((string) ($f['file'] ?? ''))), 'line' => (int) ($f['line'] ?? 0)])->all();
            Log::warning('research.blueprint.runtime_failed', ['exception_class' => preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($last['exception_class'] ?? 'Unknown')), 'frames' => $frames, 'exit_code' => $process->getExitCode()]);
            throw new \RuntimeException('nvidia_blueprint_runtime_failed');
        }

        return $last;
    }

    public function health(): array
    {
        if (! $this->configured()) {
            return ['status' => 'setup_required', 'configured' => false, 'runtime_checked' => false];
        }
        $fingerprint = hash('sha256', config('research.blueprint_python').config('research.blueprint_root').hash_file('sha256', base_path('runtime/nvidia-blueprint/runner.py')).hash_file('sha256', base_path('runtime/nvidia-blueprint/config.yml')));

        return Cache::remember('research:blueprint:'.$fingerprint, 60, function () {
            try {
                $p = $this->process(['--check'])->setTimeout(25);
                $p->run();
                $out = $this->result($p);
                $ready = ($out['ready'] ?? false) && ($out['upstream_commit'] ?? null) === self::COMMIT;

                return ['status' => $ready ? 'ready' : 'runtime_unavailable', 'configured' => true, 'runtime_checked' => $ready, 'upstream_commit' => self::COMMIT, 'sandbox_execution' => false];
            } catch (\Throwable) {
                return ['status' => 'runtime_unavailable', 'configured' => true, 'runtime_checked' => false];
            }
        });
    }

    public function research(Context $ctx): array
    {
        abort_unless($this->configured(), 503, 'NVIDIA Deep Researcher runtime is not configured.');
        $model = collect(array_merge([(string) ($ctx->project()->settings['models']['nvidia'] ?? config('research.model'))], config('research.fallback_models')))->map(fn ($slug) => DB::table('hosted_code_agents')->where('slug', $slug)->where('enabled', true)->where('api_ready', true)->where('api_protocol', 'chat')->where('last_test_status', 'success')->first())->first(fn ($m) => $m !== null);
        abort_unless($model, 503, 'A verified NVIDIA research model is required.');
        $token = Crypt::encryptString(json_encode(['task_id' => $ctx->task->id, 'claim' => $ctx->claim, 'actor_id' => $ctx->run()->actor_id, 'model' => $model->provider_model, 'expires' => now()->addMinutes(75)->timestamp]));
        $input = ['input' => $ctx->project()->prompt."\nWrite the report in ".($ctx->project()->settings['output_language'] ?? 'the requested language').'. Use real retrieved sources with citations. This is a research proposal: never claim experiments, code execution or field validation were performed. Explain limitations and AI assistance.'];
        $base = rtrim((string) config('app.url'), '/').'/api/v1/internal/blueprint';
        abort_unless(str_starts_with($base, 'https://'), 503, 'The research bridge requires HTTPS.');
        $p = $this->process([], ['OPENMIND_BLUEPRINT_TOKEN' => $token, 'OPENMIND_BLUEPRINT_BASE' => $base, 'OPENMIND_BLUEPRINT_MODEL' => $model->provider_model]);
        $p->setInput(json_encode($input, JSON_UNESCAPED_UNICODE))->setTimeout(3900);
        $lock = fopen(storage_path('framework/nvidia-runtime.lock'), 'c');
        abort_unless($lock, 503, 'NVIDIA runtime lock is unavailable.');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new HttpException(429, 'blueprint_runtime_busy', null, ['Retry-After' => 30]);
        }
        try {
            $ctx->event('blueprint.started', ['engine' => 'nvidia-deep-researcher-2.2', 'upstream_commit' => self::COMMIT]);
            $p->run(function ($type, $buffer) use ($p) {
                if (strlen($p->getOutput()) + strlen($p->getErrorOutput()) > 8000000) {
                    $p->stop();
                    throw new \RuntimeException('blueprint_output_limit');
                }if ($type === Process::ERR && strlen($p->getErrorOutput()) > 65536) {
                    $p->clearErrorOutput();
                }
            });
            $out = $this->result($p);
            abort_unless(($out['upstream_commit'] ?? null) === self::COMMIT && ! ($out['sandbox_execution'] ?? true), 502, 'Invalid NVIDIA runtime provenance.');

        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $out;
    }
}
