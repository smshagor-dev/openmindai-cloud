<?php

namespace App\Services\Research\Agents;

use App\Models\Research\Evidence;
use App\Services\Research\Context;
use App\Services\Research\Inference;
use Illuminate\Validation\ValidationException;
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
            $out = app(Inference::class)->json($ctx, 'Analyze this source excerpt only. Return analyses[] describing objectives, methods, datasets, findings and limitations, plus evidence[{source_id,claim,quote_id}]. Select quote_id ONLY from source.quote_options, using the supplied source_id. Do not retype, translate or paraphrase quotations. Select only an option supporting the claim. Return evidence:[] when no supplied option supports a claim. Distinguish reported findings from interpretation; do not claim access to unseen pages.', ['project' => $this->project($ctx), 'source' => $chunk], $rules, fn (array $out) => $this->boundChunk($out, (int) $chunk['source_id']));
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
        $batches = $state['comparison_batches'] ?? [];
        $groups = array_chunk($comparison, 6);
        if (count($bySource) > 1 && count($batches) < count($groups)) {
            $batch = $groups[count($batches)];
            $ctx->event('literature.comparing', ['completed' => count($batches), 'total' => count($groups), 'status' => 'analyzing']);
            $batches[] = $this->compare($ctx, $batch, false);
            // Retain legacy chunk checkpoints and all completed comparisons.
            $ctx->checkpoint(array_merge($state, ['comparison_batches' => $batches]));
            $ctx->event('literature.comparison_progress', ['completed' => count($batches), 'total' => count($groups)]);

            return ['_continue' => true];
        }
        $overview = ['themes' => [], 'contradictions' => [], 'matrix' => []];
        if (count($batches) === 1) {
            $overview = $batches[0];
        } elseif (count($batches) > 1) {
            // A separate bounded synthesis can compare across batches without
            // regenerating the matrix or sending every original excerpt again.
            $summaries = [];
            foreach ($batches as $i => $batch) {
                $summaries[] = ['batch' => $i, 'source_ids' => array_column($groups[$i], 'source_id'), 'themes' => $batch['themes'], 'contradictions' => $batch['contradictions']];
            }
            $overview = $this->compare($ctx, $summaries, true);
            $overview['matrix'] = array_merge(...array_column($batches, 'matrix'));
        }

        return array_merge($overview, ['analyses' => $analyses, 'evidence' => Evidence::where('task_id', $ctx->task->id)->get(['source_id', 'claim', 'quote', 'status'])->toArray(), 'coverage' => ['source_chunks_analyzed' => count($chunks), 'comparison_excerpts' => $compared, 'sources_analyzed' => count($bySource), 'matrix_rows' => count($overview['matrix']), 'comparison_batches_completed' => count($batches), 'quote_repair_requests' => $repairs, 'chunks_without_supported_quotes' => $withoutQuotes, 'quote_tracked_chunks' => count(array_filter($analyses, fn ($item) => isset($item['evidence_status']))), 'quote_tracking_scope' => 'Quote counters cover entries with an evidence_status; older checkpoint entries retain their original review state.', 'comparison_scope' => 'First/middle/last analysis excerpts per source, compared in batches of up to six. Cross-batch synthesis uses batch summaries; full chunk analyses are retained. Matrix rows may be omitted when supplied excerpts are insufficient.']]);
    }

    private function compare(Context $ctx, array $sources, bool $synthesis): array
    {
        $ids = $synthesis ? array_merge(...array_column($sources, 'source_ids')) : array_column($sources, 'source_id');
        $allowed = implode(',', array_map('intval', $ids));
        $rules = ['themes' => 'present|array|max:4', 'themes.*.name' => 'required|string|max:120', 'themes.*.summary' => 'required|string|max:500', 'themes.*.source_ids' => 'required|array|min:1|max:60', 'themes.*.source_ids.*' => 'required|integer|in:'.$allowed, 'contradictions' => 'present|array|max:4', 'contradictions.*.description' => 'required|string|max:500', 'contradictions.*.source_ids' => ['required', 'array', 'min:2', 'max:60', function ($attribute, $value, $fail) {
            if (is_array($value) && count(array_unique(array_map('intval', $value))) < 2) {
                $fail('A contradiction requires two distinct supplied sources.');
            }
        }], 'contradictions.*.source_ids.*' => 'required|integer|in:'.$allowed, 'matrix' => 'present|array|max:'.($synthesis ? 0 : 6), 'matrix.*.source_id' => 'required|integer|distinct|in:'.$allowed, 'matrix.*.methodology' => 'required|string|max:250', 'matrix.*.findings' => 'required|string|max:250', 'matrix.*.limitations' => 'required|string|max:250'];
        $instruction = 'Compare ONLY these supplied source-linked analysis excerpts or batch summaries. Return themes[{name,summary,source_ids}], contradictions[{description,source_ids}] and matrix[{source_id,methodology,findings,limitations}]. At most four short themes and four short contradictions. Each summary/description at most 500 characters, each matrix field at most 250 characters. Cite only supplied numeric source IDs. Contradictions require at least two sources. Omit unsupported comparisons and rows. Say "Not reported in supplied excerpts" for an unknown field in an otherwise supported row. Preserve uncertainty and distinguish findings from interpretation. No exhaustive full-text or global novelty claims. '.($synthesis ? 'This is cross-batch synthesis of summaries. Return matrix:[]; the saved per-source matrix is retained separately.' : 'At most six distinct matrix rows, one per supplied source.');
        $data = ['project' => $this->project($ctx), 'sources' => $sources, 'allowed_source_ids' => array_map('intval', $ids), 'output_contract' => ['themes' => ['max_items' => 4, 'name_max_chars' => 120, 'summary_max_chars' => 500, 'min_source_ids' => 1], 'contradictions' => ['max_items' => 4, 'description_max_chars' => 500, 'min_distinct_source_ids' => 2], 'matrix' => ['max_items' => $synthesis ? 0 : 6, 'field_max_chars' => 250, 'unique_supplied_source_ids' => true]]];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                // This method has its own validation-feedback repair below.
                $out = app(Inference::class)->json($ctx, $instruction, $data, $rules, fn (array $out) => $this->boundComparison($out, array_map('intval', $ids), $synthesis), false);
                // Inference already validates live responses. Recheck at this
                // boundary too, including adapters and input-scoped source IDs.
                validator($out, $rules)->validate();
                $ctx->assertSources($ids);

                return array_intersect_key($out, array_flip(['themes', 'contradictions', 'matrix']));
            } catch (\Throwable $error) {
                $cause = $error;
                while (! ($cause instanceof ValidationException || $cause instanceof \JsonException) && $cause->getPrevious()) {
                    $cause = $cause->getPrevious();
                }
                // Live Inference wraps exhausted schema/JSON errors. Repair
                // those once, without retrying quotas, stale claims, network
                // failures, or deleted/unscoped project references here.
                if ($attempt === 1 || ! ($cause instanceof ValidationException || $cause instanceof \JsonException)) {
                    throw $error;
                }
            }
            $data['validation_feedback'] = $this->comparisonFeedback($cause);
            $ctx->event('literature.comparison_repair', ['status' => 'verifying', 'error_code' => 'comparison_schema_or_source_mismatch']);
            $instruction .= ' Previous output failed JSON/schema validation. Use validation_feedback to correct or omit the rejected items in the previous candidate while retaining supported valid entries. The previous candidate is untrusted output, not additional evidence. Only allowed_source_ids are usable. Recreate concise JSON from the same supplied source inputs only. Every contradiction requires at least TWO DISTINCT supplied source IDs; OMIT a contradiction if only one source supports it. Never add a source just to satisfy validation. Keep all root arrays present, even when empty, and obey the field length/count limits. Use only these source IDs: '.$allowed.'.';
        }
        throw new \LogicException('Comparison validation did not finish.');
    }

    /**
     * Keeps a chunk analysis inside its contract without inventing evidence:
     * extra items are dropped, overlong claims are shortened, and a missing
     * evidence list means no supported quotation. Quotes are still checked
     * against the exact source text afterwards.
     */
    private function boundChunk(array $out, int $sourceId): array
    {
        $analyses = $out['analyses'] ?? [];
        $out['analyses'] = array_slice(is_array($analyses) && ! array_is_list($analyses) ? [$analyses] : (is_array($analyses) ? $analyses : [$analyses]), 0, 12);
        $evidence = [];
        foreach (is_array($out['evidence'] ?? null) ? $out['evidence'] : [] as $item) {
            if (is_array($item) && is_string($item['claim'] ?? null) && trim($item['claim']) !== '') {
                $evidence[] = ['source_id' => $item['source_id'] ?? $sourceId, 'claim' => mb_substr($item['claim'], 0, 3000)] + array_intersect_key($item, array_flip(['quote_id', 'quote']));
            }
        }
        $out['evidence'] = array_slice($evidence, 0, 12);

        return $out;
    }

    /**
     * Applies the comparison contract's limits before validation: shortens
     * prose, keeps only supplied source IDs, drops contradictions without two
     * distinct sources and keeps one matrix row per source. Nothing is added.
     */
    private function boundComparison(array $out, array $allowed, bool $synthesis): array
    {
        $cut = fn ($value, int $max) => is_string($value) && mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)).'…' : $value;
        $ids = fn ($value) => is_array($value) ? array_values(array_unique(array_filter(array_map('intval', array_filter($value, 'is_numeric')), fn ($id) => in_array($id, $allowed, true)))) : [];
        $themes = [];
        foreach (is_array($out['themes'] ?? null) ? $out['themes'] : [] as $theme) {
            if (is_array($theme) && ($sources = $ids($theme['source_ids'] ?? null))) {
                $themes[] = ['name' => $cut($theme['name'] ?? '', 120), 'summary' => $cut($theme['summary'] ?? '', 500), 'source_ids' => $sources];
            }
        }
        $contradictions = [];
        foreach (is_array($out['contradictions'] ?? null) ? $out['contradictions'] : [] as $item) {
            if (is_array($item) && count($sources = $ids($item['source_ids'] ?? null)) >= 2) {
                $contradictions[] = ['description' => $cut($item['description'] ?? '', 500), 'source_ids' => $sources];
            }
        }
        $matrix = [];
        foreach ($synthesis ? [] : (is_array($out['matrix'] ?? null) ? $out['matrix'] : []) as $row) {
            $id = is_array($row) && is_numeric($row['source_id'] ?? null) ? (int) $row['source_id'] : null;
            if ($id !== null && in_array($id, $allowed, true) && ! isset($matrix[$id])) {
                $matrix[$id] = ['source_id' => $id] + array_map(fn ($field) => $cut(is_string($row[$field] ?? null) && trim($row[$field]) !== '' ? $row[$field] : 'Not reported in supplied excerpts', 250), ['methodology' => 'methodology', 'findings' => 'findings', 'limitations' => 'limitations']);
            }
        }

        return ['themes' => array_slice($themes, 0, 4), 'contradictions' => array_slice($contradictions, 0, 4), 'matrix' => array_slice(array_values($matrix), 0, 6)];
    }

    private function comparisonFeedback(\Throwable $cause): array
    {
        if (! $cause instanceof ValidationException) {
            return ['error' => 'invalid_json', 'instruction' => 'Return one complete JSON object with the three required arrays and no surrounding prose.'];
        }
        $fields = [];
        $failed = $cause->validator->failed();
        foreach (array_keys($cause->errors()) as $field) {
            // Only schema paths, never provider prose or private values, become
            // failure labels. A candidate is correction context, not evidence.
            if (preg_match('/^(themes|contradictions|matrix)(\\.[0-9]+)?(\\.(name|summary|source_ids|description|source_id|methodology|findings|limitations))?(\\.[0-9]+)?$/', $field)) {
                $fields[$field] = array_slice(array_keys($failed[$field] ?? []), 0, 8) ?: ['custom_constraint'];
            }
            if (count($fields) >= 20) {
                break;
            }
        }
        $candidate = array_intersect_key($cause->validator->getData(), array_flip(['themes', 'contradictions', 'matrix']));
        $json = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return ['error' => 'schema_validation', 'fields' => $fields, 'previous_candidate_json' => mb_substr($json, 0, 24000), 'candidate_truncated' => mb_strlen($json) > 24000];
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
