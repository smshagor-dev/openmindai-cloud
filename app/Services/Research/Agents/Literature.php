<?php

namespace App\Services\Research\Agents;

use App\Models\Research\Evidence;
use App\Services\Research\Context;

class Literature extends BaseAgent
{
    protected array $tools = ['project_sources.read', 'exact_quote.extract', 'structured_reasoning'];

    protected array $rules = ['analyses' => 'required|array', 'evidence' => 'required|array|max:12', 'evidence.*.source_id' => 'required|integer', 'evidence.*.claim' => 'required|string|max:3000', 'evidence.*.quote' => 'required|string|max:3000'];

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
            $out = $this->ask($ctx, 'Analyze this source excerpt only. Extract objectives/methods/datasets/findings/limitations into analyses[]. Every evidence item must have source_id, claim and a VERBATIM contiguous quote from this excerpt. Distinguish author findings from interpretations. Never claim access to pages not supplied. Return analyses[], evidence[].', ['project' => $this->project($ctx), 'source' => $chunk]);
            foreach ($out['evidence'] as $e) {
                abort_unless($e['source_id'] === $chunk['source_id'] && str_contains($chunk['text'], $e['quote']) && mb_strlen($e['quote']) >= 12, 422, 'Evidence quote is not in the source excerpt.');
                $ctx->fenced(fn () => Evidence::firstOrCreate(['project_id' => $ctx->task->project_id, 'source_id' => $e['source_id'], 'task_id' => $ctx->task->id, 'claim' => $e['claim'], 'quote' => $e['quote']], ['status' => 'unreviewed']));
            }
            $analyses[] = ['source_id' => $chunk['source_id'], 'part' => $chunk['part'], 'analysis' => $out['analyses']];
            $ctx->checkpoint(['index' => $index + 1, 'analyses' => $analyses]);
            $ctx->event('literature.progress', ['completed' => $index + 1, 'total' => count($chunks), 'source_id' => $chunk['source_id']]);
            if ($index + 1 < count($chunks)) {
                return ['_continue' => true];
            }
        }

        return ['analyses' => $analyses, 'evidence' => Evidence::where('task_id', $ctx->task->id)->get(['source_id', 'claim', 'quote', 'status'])->toArray()];
    }
}
