<?php

namespace App\Services\Research\Agents;

use App\Services\Research\Context;
use Illuminate\Support\Facades\DB;

class Collaboration extends BaseAgent
{
    protected array $tools = ['project_members.read', 'review_tasks.prepare'];

    protected array $rules = ['review_assignments' => 'present|array', 'sharing' => 'required|string'];

    public function execute(Context $ctx): array
    {
        $members = DB::table('research_members')->where('project_id', $ctx->task->project_id)->whereNotNull('accepted_at')->get(['user_id', 'role'])->toArray();

        return ['review_assignments' => array_map(fn ($m) => ['user_id' => $m->user_id, 'role' => $m->role, 'task' => 'Review current manuscript and evidence; record an explicit decision.'], $members), 'sharing' => 'No invitations or external messages were sent by this agent.'];
    }
}
