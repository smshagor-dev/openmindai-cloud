<?php

namespace App\Services\Research\Agents;

use App\Models\Research\Finding;
use App\Services\Research\Context;
use App\Services\Research\Documents;

class Manuscript extends BaseAgent
{
    protected array $tools = ['manuscript.build'];

    protected array $rules = ['content' => 'present|array', 'base_version' => 'required|integer', 'disclosure' => 'required|string'];

    public function execute(Context $ctx): array
    {
        $draft = Finding::where('project_id', $ctx->task->project_id)->where('kind', 'editor')->latest('id')->firstOrFail()->data;
        $nodes = [];
        foreach ($draft['sections'] as $i => $section) {
            $nodes[] = ['type' => 'heading', 'attrs' => ['level' => 1, 'id' => 'section-'.($i + 1)], 'content' => [['type' => 'text', 'text' => $section['heading']]]];
            foreach ($section['paragraphs'] as $p) {
                $children = [['type' => 'text', 'text' => $p['text']]];
                foreach ($p['source_ids'] as $id) {
                    $children[] = ['type' => 'citation', 'attrs' => ['sourceId' => $id]];
                }$nodes[] = ['type' => 'paragraph', 'content' => $children];
            }
        }$doc = app(Documents::class)->validate($ctx->task->project_id, ['type' => 'doc', 'content' => $nodes]);

        return ['content' => $doc, 'base_version' => $draft['base_version'], 'disclosure' => 'AI-generated proposal. Review before accepting; current user document was not overwritten.'];
    }
}
