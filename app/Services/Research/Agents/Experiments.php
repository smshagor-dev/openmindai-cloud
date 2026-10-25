<?php

namespace App\Services\Research\Agents;

use App\Services\Research\Context;

class Experiments extends BaseAgent
{
    protected array $tools = ['experiment.plan', 'structured_reasoning'];

    protected array $rules = ['plans' => 'required|array', 'plans.*.title' => 'required|string', 'plans.*.method' => 'required|string', 'plans.*.code' => 'required|string|max:30000', 'plans.*.datasets_required' => 'required|array', 'disclosure' => 'required|string'];

    public function execute(Context $ctx): array
    {
        if (! ($ctx->run()->plan['experimental'] ?? false)) {
            return ['plans' => [], 'disclosure' => 'No experimental execution requested.'];
        }

return $this->ask($ctx, 'Propose reproducible Python experiment plans (title, method, code, datasets_required) using approved user datasets only. No code is executed by this agent. Use /work/input authorized files and /work/output for artifacts. Include random seed and real baselines where feasible. Return plans[] and disclosure string. Missing datasets must be explicit, not simulated results.', ['project' => $this->project($ctx), 'findings' => $ctx->findings()]);
    }
}
