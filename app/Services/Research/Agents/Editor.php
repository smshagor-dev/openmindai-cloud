<?php

namespace App\Services\Research\Agents;

use App\Models\Research\Document;
use App\Services\Research\Context;
use App\Services\Research\ExperimentArtifacts;
use App\Services\Research\Inference;

class Editor extends BaseAgent
{
    protected array $tools = ['project_findings.read', 'manuscript.propose', 'structured_reasoning'];

    protected array $rules = ['sections' => 'required|array|min:1|max:12', 'sections.*.heading' => 'required|string|max:300', 'sections.*.paragraphs' => 'required|array|min:1|max:20', 'base_version' => 'required|integer'];

    public function execute(Context $ctx): array
    {
        $state = $ctx->task->checkpoint ?? [];
        $sections = ['Title and abstract', 'Introduction', 'Related work', 'Methodology', 'Results and evidence synthesis', 'Discussion', 'Limitations', 'Conclusion and AI assistance disclosure'];
        $index = $state['index'] ?? 0;
        $draft = $state['sections'] ?? [];
        $base = $state['base_version'] ?? Document::where('project_id', $ctx->task->project_id)->value('version');
        $ctx->event('manuscript.writing', ['stage' => $sections[$index], 'status' => 'writing', 'completed' => $index, 'total' => count($sections)]);
        $out = app(Inference::class)->json($ctx, 'Draft ONLY the assigned manuscript section in the requested manuscript language. Return heading (translated to the manuscript language), paragraphs[{text,source_ids}]. Academic, precise, natural prose; no filler, invented data or references. Preserve equations/terminology. Cite only supplied source IDs. Describe absent experiments as proposed/not executed and do not create statistical results. Include study limitations and AI disclosure when assigned. Respond to supplied reviewer feedback.', ['project' => $this->project($ctx), 'section' => $sections[$index], 'findings' => $ctx->findings(), 'completed_experiments' => app(ExperimentArtifacts::class)->context($ctx->task->project_id), 'current_document' => Document::where('project_id', $ctx->task->project_id)->value('content'), 'draft' => $draft], ['heading' => 'required|string|max:300', 'paragraphs' => 'required|array|min:1|max:20', 'paragraphs.*.text' => 'required|string|max:5000', 'paragraphs.*.source_ids' => 'present|array']);
        foreach ($out['paragraphs'] as $paragraph) {
            $ctx->assertSources($paragraph['source_ids']);
        }$draft[] = ['heading' => $out['heading'], 'paragraphs' => $out['paragraphs']];
        $ctx->checkpoint(['index' => $index + 1, 'sections' => $draft, 'base_version' => $base]);
        $ctx->event('manuscript.progress', ['completed' => $index + 1, 'total' => count($sections), 'stage' => $sections[$index]]);
        if ($index + 1 < count($sections)) {
            return ['_continue' => true];
        }

        return ['paragraphs' => array_merge(...array_column($draft, 'paragraphs')), 'sections' => $draft, 'base_version' => $base];
    }
}
