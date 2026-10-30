<?php

namespace App\Services\Research\Agents;

use App\Models\Research\Document;
use App\Models\Research\Source;
use App\Services\Research\Context;

class Citations extends BaseAgent
{
    protected array $tools = ['citation.audit'];

    protected array $rules = ['references' => 'present|array', 'missing' => 'present|array', 'status' => 'required|string'];

    public function execute(Context $ctx): array
    {
        $content = Document::where('project_id', $ctx->task->project_id)->value('content');
        $ids = [];
        $walk = function ($node) use (&$walk, &$ids) {
            if (($node['type'] ?? '') === 'citation') {
                $ids[] = (int) $node['attrs']['sourceId'];
            }foreach ($node['content'] ?? [] as $c) {
                $walk($c);
            }
        };
        $walk($content);
        $ctx->assertSources($ids);

        return ['references' => Source::where('project_id', $ctx->task->project_id)->whereIn('id', $ids)->get(['id', 'title', 'doi', 'authors', 'year', 'venue', 'url', 'verification'])->toArray(), 'missing' => [], 'status' => $ids ? 'scoped' : 'no_citations_in_saved_document'];
    }
}
