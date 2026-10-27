<?php

namespace App\Services\Research\Agents;

use App\Models\Research\Evidence;
use App\Services\Research\Context;
use App\Services\Research\Inference;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        $repairs = $state['quote_repairs'] ?? 0;
        $withoutQuotes = $state['chunks_without_quotes'] ?? 0;
        if (isset($chunks[$index])) {
            $chunk = $chunks[$index];
            // The model selects an ID; the backend supplies its exact source text.
            // Existing checkpoints and legacy verbatim-quote responses remain valid.
            $options = [];
            foreach (mb_str_split($chunk['text'], 1000) as $quote) {
                if (mb_strlen(trim($quote)) >= 12) {
                    $options['q'.(count($options) + 1)] = $quote;
                }
            }
            $chunk['quote_options'] = $options;
            $rules = ['analyses' => 'present|array|max:12', 'evidence' => 'present|array|max:12', 'evidence.*.source_id' => 'required|integer', 'evidence.*.claim' => 'required|string|max:3000', 'evidence.*.quote_id' => 'sometimes|string|max:20', 'evidence.*.quote' => 'required_without:evidence.*.quote_id|string|max:3000'];
            $ctx->event('literature.reading', ['source_id' => $chunk['source_id'], 'status' => 'analyzing', 'completed' => $index, 'total' => count($chunks)]);
            $out = app(Inference::class)->json($ctx, 'Analyze this source excerpt only. Return analyses[] describing objectives, methods, datasets, findings and limitations, plus evidence[{source_id,claim,quote_id}]. Select quote_id ONLY from source.quote_options, using the supplied source_id. Do not retype, translate or paraphrase quotations. Select only an option supporting the claim. Return evidence:[] when no supplied option supports a claim. Distinguish reported findings from interpretation; do not claim access to unseen pages.', ['project' => $this->project($ctx), 'source' => $chunk], $rules);
            try {
                $evidence = $this->resolveQuotes($out['evidence'], $chunk, $options);
            } catch (HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 422) {
                    throw $error;
                }
                $ctx->event('literature.quote_repair', ['source_id' => $chunk['source_id'], 'status' => 'verifying', 'count' => count($out['evidence']), 'error_code' => 'evidence_quote_not_in_source']);
                $repair = app(Inference::class)->json($ctx, 'The previous quotation or source ID failed exact-source validation. Return ONLY evidence[{source_id,claim,quote_id}]. Select quote_id from the supplied source.quote_options and use its exact source_id. Do not generate quote text. Retain a claim only if the selected source excerpt supports it; otherwise omit it. An empty evidence array is valid and records missing evidence, not verified findings.', ['source' => $chunk, 'claims_to_check' => array_column($out['evidence'], 'claim')], array_diff_key($rules, ['analyses' => true]));
                $evidence = $this->resolveQuotes($repair['evidence'], $chunk, $options);
                $repairs++;
            }
            // Validate every candidate before persisting any of this work unit.
            foreach ($evidence as $e) {
                $ctx->fenced(fn () => Evidence::firstOrCreate(['project_id' => $ctx->task->project_id, 'source_id' => $e['source_id'], 'task_id' => $ctx->task->id, 'claim' => $e['claim'], 'quote' => $e['quote']], ['status' => 'unreviewed']));
            }
            if (! $evidence) {
                $withoutQuotes++;
                $ctx->event('literature.evidence_limited', ['source_id' => $chunk['source_id'], 'status' => 'needs_review', 'error_code' => 'no_supported_quotation']);
            }
            $analyses[] = ['source_id' => $chunk['source_id'], 'part' => $chunk['part'], 'analysis' => $out['analyses'], 'evidence_status' => $evidence ? 'unreviewed' : 'missing'];
            $ctx->checkpoint(['index' => $index + 1, 'analyses' => $analyses, 'quote_repairs' => $repairs, 'chunks_without_quotes' => $withoutQuotes]);
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

        return array_merge($overview, ['analyses' => $analyses, 'evidence' => Evidence::where('task_id', $ctx->task->id)->get(['source_id', 'claim', 'quote', 'status'])->toArray(), 'coverage' => ['source_chunks_analyzed' => count($chunks), 'comparison_excerpts' => $compared, 'sources_analyzed' => count($bySource), 'matrix_rows' => count($overview['matrix']), 'quote_repair_requests' => $repairs, 'chunks_without_supported_quotes' => $withoutQuotes, 'quote_tracked_chunks' => count(array_filter($analyses, fn ($item) => isset($item['evidence_status']))), 'quote_tracking_scope' => 'Quote counters cover entries with an evidence_status; older checkpoint entries retain their original review state.', 'comparison_scope' => 'Up to first/middle/last analysis excerpts per source; full chunk analyses are retained.']]);
    }

    private function resolveQuotes(array $candidates, array $chunk, array $options): array
    {
        $resolved = [];
        foreach ($candidates as $candidate) {
            $quote = isset($candidate['quote_id']) ? ($options[$candidate['quote_id']] ?? null) : ($candidate['quote'] ?? null);
            abort_unless((int) $candidate['source_id'] === (int) $chunk['source_id'] && is_string($quote) && mb_strlen($quote) >= 12 && str_contains($chunk['text'], $quote), 422, 'Evidence quote or source could not be verified.');
            $resolved[] = ['source_id' => (int) $chunk['source_id'], 'claim' => $candidate['claim'], 'quote' => $quote];
        }

        return $resolved;
    }
}
