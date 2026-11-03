<?php

namespace App\Services\Research\Agents;

use App\Models\Research\Document;
use App\Models\Research\Evidence;
use App\Services\Research\Context;
use App\Services\Research\Documents;
use App\Services\Research\ExperimentArtifacts;

class Quality extends BaseAgent
{
    protected array $tools = ['saved_manuscript.review', 'project_similarity', 'structured_reasoning'];

    protected array $rules = ['passed' => 'required|boolean', 'issues' => 'present|array', 'issues.*.severity' => 'required|in:info,warning,error', 'issues.*.message' => 'required|string', 'issues.*.agent' => 'required|string', 'disclosure' => 'required|string'];

    public function execute(Context $ctx): array
    {
        [$hash,$doc] = $ctx->fenced(fn () => [app(Documents::class)->hash($ctx->task->project_id), Document::where('project_id', $ctx->task->project_id)->firstOrFail()->content]);
        $text = json_encode($doc, JSON_UNESCAPED_UNICODE);
        $overlaps = [];
        preg_match_all('/[\pL\pN]+/u', mb_strtolower($text), $words);
        $phrases = [];
        for ($i = 0; $i + 10 < count($words[0]); $i += 5) {
            $phrases[] = implode(' ', array_slice($words[0], $i, 10));
        }foreach ($ctx->sources() as $s) {
            $body = mb_strtolower(preg_replace('/[^\pL\pN]+/u', ' ', (string) $s['text']));
            $hits = 0;
            foreach ($phrases as $phrase) {
                if (str_contains($body, $phrase)) {
                    $hits++;
                }
            }if ($hits) {
                $overlaps[] = ['source_id' => $s['id'], 'matching_ten_word_windows' => $hits];
            }
        }
        if (! $this->hasText($doc)) {
            $o = ['passed' => false, 'issues' => [['severity' => 'error', 'message' => 'The saved manuscript is empty. Review and apply the manuscript proposal, then run Quality again.', 'agent' => 'manuscript']], 'disclosure' => 'No manuscript review was performed; the saved document is empty.'];
        } else {
            $o = $this->review($ctx, $hash, $doc);
            if ($o['_continue'] ?? false) {
                return $o;
            }
        }
        $records = $this->documentRecords($doc);
        $citedIds = [];
        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'citation' && isset($record['attrs']['sourceId'])) {
                $citedIds[(int) $record['attrs']['sourceId']] = true;
            }
        }
        $referenceCount = count($citedIds);
        $o['reference_coverage'] = ['distinct_cited_sources' => $referenceCount, 'minimum' => 25];
        if ($referenceCount < 25) {
            $o['passed'] = false;
            $o['issues'][] = [
                'severity' => 'error',
                'message' => "The manuscript cites {$referenceCount} distinct sources. Cite at least 25 relevant sources where they support the manuscript.",
                'agent' => 'citations',
            ];
        }
        $o['content_hash'] = $hash;
        $o['similarity'] = ['scope' => 'project_sources_only', 'overlaps' => $overlaps, 'global_check' => 'not_available'];

        return $o;
    }

    private function review(Context $ctx, string $hash, array $doc): array
    {
        // Keep every evidence record, but never send the entire project corpus in one request.
        $evidence = Evidence::where('project_id', $ctx->task->project_id)->orderBy('id')->get(['id', 'source_id', 'claim', 'quote', 'status'])->toArray();
        $documents = $this->batches($this->documentRecords($doc));
        $evidenceBatches = $this->batches($this->evidenceRecords($evidence));
        $project = $this->project($ctx);
        $executions = app(ExperimentArtifacts::class)->context($ctx->task->project_id);
        $fingerprint = hash('sha256', json_encode([$hash, $project, $executions, $evidence], JSON_UNESCAPED_UNICODE));
        $checkpoint = $ctx->task->checkpoint ?? [];
        if (($checkpoint['review_fingerprint'] ?? null) !== $fingerprint) {
            $checkpoint = ['review_fingerprint' => $fingerprint, 'next' => 0, 'result' => ['passed' => true, 'issues' => [], 'disclosure' => 'AI review of the saved manuscript and project evidence in bounded batches; no global plagiarism certification.']];
        }
        $total = count($documents) * count($evidenceBatches);
        $index = $checkpoint['next'];
        if ($index < $total) {
            $part = $this->ask($ctx, 'Review the supplied ACTUAL saved manuscript records for coherent sections, methods, whether citations support nearby claims, unrelated or redundant citations, unsupported factual/novelty/experimental claims, limitations, disclosure and supplied layout settings. Records retain document paths; long text/evidence fields are contiguous numbered fragments, not omitted content. This is a partial review: do not report missing manuscript sections or missing evidence merely because they occur in another batch. Flag concrete problems visible here. Do not treat supplied evidence as exhaustive or certify global plagiarism freedom. Return passed boolean, issues[{severity:info|warning|error,message,agent}], disclosure.', [
                'project' => $project,
                'review_scope' => ['batch' => $index + 1, 'total' => $total, 'partial' => $total > 1, 'document_batches' => count($documents), 'evidence_batches' => count($evidenceBatches)],
                'document' => $documents[intdiv($index, count($evidenceBatches))],
                'executions' => $executions,
                'evidence' => $evidenceBatches[$index % count($evidenceBatches)],
            ]);
            $checkpoint['result']['passed'] = $checkpoint['result']['passed'] && $part['passed'];
            foreach ($part['issues'] as $issue) {
                if (! in_array($issue, $checkpoint['result']['issues'], true)) {
                    $checkpoint['result']['issues'][] = $issue;
                }
            }
            $checkpoint['next']++;
            $checkpoint['total'] = $total;
            $ctx->checkpoint($checkpoint);
        }

        return $checkpoint['next'] < $total ? ['_continue' => true] : $checkpoint['result'];
    }

    private function documentRecords(array $doc, string $path = 'doc'): array
    {
        $record = array_diff_key($doc, ['content' => true, 'text' => true]);
        $record['path'] = $path;
        $records = [];
        foreach (mb_str_split($doc['text'] ?? '', 20000) ?: [''] as $i => $text) {
            $records[] = $record + ['text' => $text, 'text_part' => $i + 1];
        }
        foreach ($doc['content'] ?? [] as $i => $child) {
            array_push($records, ...$this->documentRecords($child, $path.'.'.$i));
        }

        return $records;
    }

    private function evidenceRecords(array $evidence): array
    {
        $records = [];
        foreach ($evidence as $row) {
            $metadata = array_diff_key($row, ['claim' => true, 'quote' => true]);
            foreach (['claim', 'quote'] as $field) {
                foreach (mb_str_split($row[$field], 20000) ?: [''] as $i => $text) {
                    $records[] = $metadata + ['field' => $field, 'part' => $i + 1, 'text' => $text];
                }
            }
        }

        return $records;
    }

    private function batches(array $records): array
    {
        $batches = [[]];
        $size = 0;
        foreach ($records as $record) {
            $length = mb_strlen(json_encode($record, JSON_UNESCAPED_UNICODE));
            if ($size + $length > 90000 && $size > 0) {
                $batches[] = [];
                $size = 0;
            }
            $batches[array_key_last($batches)][] = $record;
            $size += $length;
        }

        return $batches;
    }

    private function hasText(array $doc): bool
    {
        $text = '';
        $walk = function ($n) use (&$walk, &$text) {
            if (($n['type'] ?? '') === 'text') {
                $text .= $n['text'] ?? '';
            }foreach ($n['content'] ?? [] as $c) {
                $walk($c);
            }
        };
        $walk($doc);

        return mb_strlen(trim($text)) >= 80;
    }
}
