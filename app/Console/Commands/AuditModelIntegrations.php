<?php

namespace App\Console\Commands;

use App\Services\HostedCodeAgents;
use App\Services\HostedModelRuntime;
use App\Services\NvidiaModelProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditModelIntegrations extends Command
{
    protected $signature = 'models:audit {--live : Send small provider probes for supported protocols} {--model=* : Limit to specific catalog slugs} {--timeout=45 : Per-model timeout in seconds}';

    protected $description = 'Audit each registered model without exposing credentials or claiming untested media inference';

    public function handle(HostedCodeAgents $agents, HostedModelRuntime $runtime, NvidiaModelProbe $probe): int
    {
        $slugs = $this->option('model');
        $models = $agents->all();
        if ($slugs) {
            $unknown = array_diff($slugs, $models->pluck('slug')->all());
            if ($unknown) {
                $this->error('Unknown model: '.implode(', ', $unknown));

                return self::FAILURE;
            }
            $models = $models->whereIn('slug', $slugs);
        }
        foreach ($models as $model) {
            $readiness = $runtime->inspect($model->slug);
            $result = $this->option('live') ? $probe->run($model, (int) $this->option('timeout'))
                : ['status' => $readiness['configured'] ? 'not_tested' : 'setup_required', 'http_status' => null, 'runtime' => $readiness];
            if ($this->option('live') && ! in_array($result['status'], ['task_input_required', 'setup_required'], true)) {
                DB::table('hosted_code_agents')->where('id', $model->id)->update([
                    'last_tested_at' => now(), 'last_test_status' => $result['status'],
                    'last_test_http_status' => $result['http_status'], 'updated_at' => now(),
                ]);
            }
            $this->line(json_encode(['model' => $model->slug, ...$result], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
