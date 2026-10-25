<?php

namespace App\Services\Research\Agents;

use App\Models\Research\Document;
use App\Models\Research\Evidence;
use App\Services\Research\Context;
use App\Services\Research\Documents;

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
        $o = $this->ask($ctx, 'Review this ACTUAL saved manuscript for coherent sections, methods, citation coverage, unsupported factual/novelty/experimental claims, limitations, disclosure and supplied layout settings. A missing/empty manuscript must fail. Return passed boolean, issues[{severity:info|warning|error,message,agent}], disclosure. Do not certify global plagiarism freedom.', ['project' => $this->project($ctx), 'document' => $doc, 'evidence' => Evidence::where('project_id', $ctx->task->project_id)->get()->toArray()]);
        if (! $this->hasText($doc)) {
            $o['passed'] = false;
        }$o['content_hash'] = $hash;
        $o['similarity'] = ['scope' => 'project_sources_only', 'overlaps' => $overlaps, 'global_check' => 'not_available'];

        return $o;
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
