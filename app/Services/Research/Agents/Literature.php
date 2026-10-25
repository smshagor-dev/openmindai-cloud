<?php

namespace App\Services\Research\Agents;

use App\Models\Research\Evidence;
use App\Services\Research\Context;
use App\Services\Research\Inference;

class Literature extends BaseAgent
{
    protected array $tools = ['project_sources.read', 'exact_quote.extract', 'structured_reasoning'];

    protected array $rules = ['analyses' => 'present|array', 'evidence' => 'present|array', 'themes' => 'present|array', 'contradictions' => 'present|array', 'matrix' => 'present|array', 'coverage' => 'required|array'];

    public function execute(Context $ctx): array
    {
        $state = $ctx->task->checkpoint ?? [];
        $chunks = [];
        foreach ($ctx->sources() as $s) {
            foreach (mb_str_split((string) $s['text'], 8000) as $i => $text) {
                if (trim($text) !== '') {
                    $chunks[] = ['source_id' => $s['id'], 'title' => $s['title'], 'part' => $i, 'text' => $text];
                }
            }
        }
        $index = $state['index'] ?? 0;
        $analyses = $state['analyses'] ?? [];
        if (isset($chunks[$index])) {
            $chunk = $chunks[$index];
            $ctx->event('literature.reading', ['source_id' => $chunk['source_id'], 'status' => 'analyzing', 'completed' => $index, 'total' => count($chunks)]);
            $out = app(Inference::class)->json($ctx, 'Analyze this source excerpt only. Return analyses[] describing objectives, methods, datasets, findings and limitations, plus evidence[{source_id,claim,quote}]. Each quote MUST be a contiguous verbatim excerpt. No evidence is preferable to fabricated evidence. Distinguish reported findings from interpretation; do not claim access to unseen pages.', ['project' => $this->project($ctx), 'source' => $chunk], ['analyses' => 'present|array|max:12', 'evidence' => 'present|array|max:12', 'evidence.*.source_id' => 'required|integer', 'evidence.*.claim' => 'required|string|max:3000', 'evidence.*.quote' => 'required|string|max:3000']);
            foreach ($out['evidence'] as $e) {
                abort_unless($e['source_id'] === $chunk['source_id'] && str_contains($chunk['text'], $e['quote']) && mb_strlen($e['quote']) >= 12, 422, 'Quote not found in this source excerpt.');
                $ctx->fenced(fn () => Evidence::firstOrCreate(['project_id' => $ctx->task->project_id, 'source_id' => $e['source_id'], 'task_id' => $ctx->task->id, 'claim' => $e['claim'], 'quote' => $e['quote']], ['status' => 'unreviewed']));
            }
            $analyses[] = ['source_id' => $chunk['source_id'], 'part' => $chunk['part'], 'analysis' => $out['analyses']];
            $ctx->checkpoint(['index' => $index + 1, 'analyses' => $analyses]);
            $ctx->event('literature.progress', ['source_id' => $chunk['source_id'], 'completed' => $index + 1, 'total' => count($chunks)]);

            return ['_continue' => true];
        }
        $bySource = [];
        foreach ($analyses as $item) {
            $bySource[$item['source_id']][] = $item;
        }
        $comparison = [];
        $compared = 0;
        foreach ($bySource as $id => $items) {
            $selected = array_unique([0, (int) floor((count($items) - 1) / 2), count($items) - 1]);
            $excerpts = [];
            foreach ($selected as $i) {
                $excerpts[] = mb_substr(json_encode($items[$i]['analysis'], JSON_UNESCAPED_UNICODE), 0, 900);
            }$compared += count($excerpts);
            $comparison[] = ['source_id' => $id, 'chunks_analyzed' => count($items), 'comparison_excerpts' => $excerpts];
        }
        $overview = ['themes' => [], 'contradictions' => [], 'matrix' => []];
        if (count($bySource) > 1) {
            $overview = app(Inference::class)->json($ctx, 'Compare these source-linked analysis excerpts. Return themes[{name,summary,source_ids}], contradictions[{description,source_ids}] and matrix[{source_id,methodology,findings,limitations}]. Use at most 20 representative matrix rows. Preserve uncertainty and distinguish source findings from interpretation. This is a bounded comparison of supplied excerpts; do not claim exhaustive full-text comparison or global novelty. Every substantive comparison must cite supplied source IDs.', ['project' => $this->project($ctx), 'sources' => $comparison], ['themes' => 'present|array|max:10', 'themes.*.name' => 'required|string|max:200', 'themes.*.summary' => 'required|string|max:1500', 'themes.*.source_ids' => 'required|array|min:1', 'contradictions' => 'present|array|max:10', 'contradictions.*.description' => 'required|string|max:1500', 'contradictions.*.source_ids' => 'required|array|min:1', 'matrix' => 'present|array|max:20', 'matrix.*.source_id' => 'required|integer', 'matrix.*.methodology' => 'required|string|max:500', 'matrix.*.findings' => 'required|string|max:500', 'matrix.*.limitations' => 'required|string|max:500']);
            foreach (array_merge($overview['themes'], $overview['contradictions']) as $item) {
                $ctx->assertSources($item['source_ids']);
            }$ctx->assertSources(array_column($overview['matrix'], 'source_id'));
        }

        return array_merge($overview, ['analyses' => $analyses, 'evidence' => Evidence::where('task_id', $ctx->task->id)->get(['source_id', 'claim', 'quote', 'status'])->toArray(), 'coverage' => ['source_chunks_analyzed' => count($chunks), 'comparison_excerpts' => $compared, 'sources_analyzed' => count($bySource), 'matrix_rows' => count($overview['matrix']), 'comparison_scope' => 'Up to first/middle/last analysis excerpts per source; full chunk analyses are retained.']]);
    }
}
