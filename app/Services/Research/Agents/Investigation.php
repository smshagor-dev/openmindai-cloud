<?php

namespace App\Services\Research\Agents;

use App\Services\Research\Context;

class Investigation extends BaseAgent
{
    protected array $tools = ['project_evidence.read', 'structured_reasoning'];

    protected array $inputs = ['perspective' => 'required|array'];

    protected array $rules = ['questions' => 'required|array', 'hypotheses' => 'required|array', 'synthesis' => 'required|string', 'source_ids' => 'required|array', 'uncertainties' => 'required|array', 'revisit_query' => 'nullable|string|max:300'];

    public function execute(Context $ctx): array
    {
        $out = $this->ask($ctx, 'Investigate the assigned perspective from supplied evidence. Return questions[], testable hypotheses[], synthesis string, source_ids[], uncertainties[], optional revisit_query (one focused search if missing evidence materially changes conclusions). Treat hypotheses as untested; preserve uncertainty and cite scoped source IDs. Research synthesis is not experimental results.', ['project' => $this->project($ctx), 'perspective' => $ctx->task->input['perspective'], 'findings' => $ctx->findings()]);
        $ctx->assertSources($out['source_ids']);

        return $out;
    }
}
