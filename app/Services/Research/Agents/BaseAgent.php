<?php

namespace App\Services\Research\Agents;

use App\Services\Research\Agent;
use App\Services\Research\Context;
use App\Services\Research\Inference;

abstract class BaseAgent implements Agent
{
    protected array $tools = [];

    protected array $rules = [];

    protected array $inputs = [];

    public function definition(): array
    {
        $permissions = ['read_project', 'write_task_scoped_findings'];
        if (in_array('crossref.search', $this->tools, true)) {
            $permissions[] = 'persist_project_sources';
        }
        if (in_array('exact_quote.extract', $this->tools, true)) {
            $permissions[] = 'persist_unreviewed_evidence';
        }
        if (in_array('crossref.doi.verify', $this->tools, true)) {
            $permissions[] = 'update_reference_verification';
        }

        return ['responsibility' => static::class, 'tools' => $this->tools, 'permissions' => $permissions, 'input_schema' => $this->inputs, 'output_schema' => $this->rules];
    }

    protected function ask(Context $ctx, string $instruction, array $data): array
    {
        return app(Inference::class)->json($ctx, $instruction, $data, $this->rules);
    }

    protected function project(Context $ctx): array
    {
        $p = $ctx->project();

        return ['topic' => $p->prompt, 'settings' => $p->settings, 'plan' => $ctx->run()->plan];
    }
}
