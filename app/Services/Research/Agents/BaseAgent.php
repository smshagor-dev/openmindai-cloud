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
        return ['responsibility' => static::class, 'tools' => $this->tools, 'permissions' => ['read_project', 'write_task_scoped_findings'], 'input_schema' => $this->inputs, 'output_schema' => $this->rules];
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
