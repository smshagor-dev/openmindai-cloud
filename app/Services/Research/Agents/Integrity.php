<?php

namespace App\Services\Research\Agents;

use App\Services\Research\Context;
use App\Services\Research\IntegrityGates;

class Integrity extends BaseAgent
{
    protected array $tools = ['evidence.audit', 'execution_provenance.audit', 'approval.audit'];

    protected array $rules = ['gates' => 'required|array', 'passed' => 'required|boolean'];

    public function execute(Context $ctx): array
    {
        return app(IntegrityGates::class)->inspect($ctx->task->project_id);
    }
}
