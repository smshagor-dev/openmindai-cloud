<?php

namespace App\Models\Research;

use App\Services\Research\ExecutionProvenance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Experiment extends Model
{
    protected $table = 'research_experiments';

    protected $guarded = ['id'];

    public function approvalHash(): string
    {
        return hash('sha256', json_encode([$this->code, array_values(array_map('intval', $this->dataset_ids)), (int) $this->seed]));
    }

    public function hasApproval(): bool
    {
        return hash_equals($this->code_hash, $this->approvalHash()) && DB::table('research_approvals')->where('project_id', $this->project_id)->where('gate', 'experiment:'.$this->id)->where('user_id', $this->approved_by)->where('content_hash', $this->code_hash)->where('override', false)->exists();
    }

    public function verifiedExecution(): bool
    {
        $p = $this->result['provenance'] ?? [];

        return $this->status === 'completed' && $this->hasApproval() && ($this->result['exit_code'] ?? -1) === 0 && ($p['execution_id'] ?? '') === $this->execution_id && ($p['code_hash'] ?? '') === hash('sha256', $this->code) && ($p['seed'] ?? null) === $this->seed && ExecutionProvenance::runtime($p);
    }

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'seed' => 'integer', 'approved_by' => 'integer', 'dataset_ids' => 'array', 'result' => 'array'];
    }
}
