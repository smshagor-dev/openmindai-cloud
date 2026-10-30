<?php

namespace App\Services\Research\Agents;

use App\Services\Research\Context;
use Illuminate\Support\Facades\DB;

class Experiments extends BaseAgent
{
    protected array $tools = ['experiment.plan', 'structured_reasoning'];

    protected array $rules = ['plans' => 'present|array', 'plans.*.title' => 'required|string', 'plans.*.method' => 'required|string', 'plans.*.code' => 'required|string|max:30000', 'plans.*.datasets_required' => 'present|array', 'disclosure' => 'required|string'];

    public function execute(Context $ctx): array
    {
        if (! ($ctx->run()->plan['experimental'] ?? false)) {
            return ['plans' => [], 'disclosure' => 'No experimental execution requested.'];
        }

        return $this->ask($ctx, 'Propose reproducible Python experiment plans (title, method, code, datasets_required) using approved user datasets only. No code is executed by this agent. Use /work/input authorized files and /work/output for artifacts. Include random seed and real baselines where feasible. Return plans[] and disclosure string. Missing datasets must be explicit, not simulated results.', ['project' => $this->project($ctx), 'findings' => $ctx->findings(), 'available_datasets' => DB::table('research_artifacts')->where('project_id', $ctx->task->project_id)->where('kind', 'dataset')->get(['id', 'name', 'bytes', 'sha256'])->map(fn ($a) => ['id' => $a->id, 'original_name' => $a->name, 'execution_path' => '/work/input/dataset-'.$a->id.'.csv', 'bytes' => $a->bytes, 'sha256' => $a->sha256])->all()]);
    }
}
