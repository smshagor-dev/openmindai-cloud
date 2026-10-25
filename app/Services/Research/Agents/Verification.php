<?php

namespace App\Services\Research\Agents;

use App\Models\Research\Source;
use App\Services\Research\Context;
use App\Services\Research\Scholarly;

class Verification extends BaseAgent
{
    protected array $tools = ['crossref.doi.verify'];

    protected array $rules = ['references' => 'required|array'];

    public function execute(Context $ctx): array
    {
        $ids = Source::where('project_id', $ctx->task->project_id)->orderBy('id')->pluck('id')->all();
        $i = $ctx->task->checkpoint['index'] ?? 0;
        $results = $ctx->task->checkpoint['references'] ?? [];
        if (isset($ids[$i])) {
            $results[] = app(Scholarly::class)->verify($ctx, Source::findOrFail($ids[$i]));
            $ctx->checkpoint(['index' => $i + 1, 'references' => $results]);
            $ctx->event('references.progress', ['completed' => $i + 1, 'total' => count($ids)]);
            if ($i + 1 < count($ids)) {
                return ['_continue' => true];
            }
        }

return ['references' => $results];
    }
}
