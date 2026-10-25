<?php

namespace App\Services\Research;

use App\Models\Research\Evidence;
use App\Models\Research\Experiment;
use App\Models\Research\Finding;
use App\Models\Research\Source;
use Illuminate\Support\Facades\DB;

class IntegrityGates
{
    public function inspect(int $id): array
    {
        $hash = app(Documents::class)->hash($id);
        $sources = Source::where('project_id', $id)->get();
        $e = Evidence::where('project_id', $id)->get();
        $cited = DB::table('research_citations')->where('project_id', $id)->pluck('source_id')->unique();
        $evidence = $cited->isNotEmpty() && $e->isNotEmpty() && $sources->whereIn('id', $cited)->every(fn ($s) => $s->verification === 'verified' && ! in_array($s->metadata['parse_status'] ?? 'completed', ['pending', 'running', 'failed'])) && $e->every(fn ($v) => $v->status === 'supported');
        $experiments = Experiment::where('project_id', $id)->get();
        $exp = $experiments->every(fn ($x) => $x->status === 'completed' && isset($x->result['provenance']['execution_id']));
        $quality = Finding::where('project_id', $id)->where('kind', 'quality')->latest('id')->first();
        $review = $quality && ($quality->data['content_hash'] ?? '') === $hash && ($quality->data['passed'] ?? false) && DB::table('research_approvals')->where('project_id', $id)->where('gate', 'manuscript')->where('content_hash', $hash)->where('override', false)->exists();
        $gates = ['evidence' => $evidence, 'experiment' => $exp, 'manuscript' => (bool) $review];
        $overrides = [];
        foreach ($gates as $g => $ok) {
            if (! $ok && DB::table('research_approvals')->where('project_id', $id)->where('gate', $g)->where('content_hash', $hash)->where('override', true)->exists()) {
                $gates[$g] = true;
                $overrides[] = $g;
            }
        }

        return ['hash' => $hash, 'gates' => $gates, 'overrides' => $overrides, 'passed' => ! in_array(false, $gates, true), 'experimental_claim_policy' => 'Only completed, approved executions may be reported as results.'];
    }
}
