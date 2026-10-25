<?php

namespace App\Services\Research\Agents;

use App\Services\Research\Context;
use App\Services\Research\Memory;

class QuestionAnswer extends BaseAgent
{
    protected array $tools = ['project_memory.retrieve', 'structured_reasoning'];

    protected array $inputs = ['question' => 'required|string|max:4000', 'source_id' => 'nullable|integer'];

    protected array $rules = ['answer' => 'required|string', 'source_ids' => 'required|array', 'uncertainty' => 'required|string'];

    public function execute(Context $ctx): array
    {
        $m = app(Memory::class)->retrieve($ctx, $ctx->task->input['question'], $ctx->task->input['source_id'] ?? null);
        $o = $this->ask($ctx, 'Answer using only the supplied project source chunks. Return answer, source_ids, uncertainty. Say clearly when supplied documents do not establish the answer; do not pretend to browse.', ['project' => $this->project($ctx), 'question' => $ctx->task->input['question'], 'memory' => $m]);
        $ctx->assertSources($o['source_ids']);
        $o['retrieval'] = $m;

        return $o;
    }
}
