<?php

namespace App\Services\Research\Agents;

use App\Services\Research\Context;

class Gaps extends BaseAgent
{
    protected array $tools = ['project_findings.read', 'structured_reasoning'];

    protected array $rules = ['gaps' => 'present|array', 'gaps.*.question' => 'required|string', 'gaps.*.evidence' => 'required|string', 'gaps.*.source_ids' => 'present|array', 'limitations' => 'present|array'];

    public function execute(Context $ctx): array
    {
        $o = $this->ask($ctx, 'Compare methods, conflicting findings and limitations to propose evidence-supported research gaps. Return gaps[{question,evidence,source_ids,novelty_uncertainty}], limitations[]. Do not assert global novelty; distinguish corpus gaps from established field-wide gaps.', ['project' => $this->project($ctx), 'findings' => $ctx->findings()]);
        foreach ($o['gaps'] as $g) {
            $ctx->assertSources($g['source_ids']);
        }

        return $o;
    }
}
