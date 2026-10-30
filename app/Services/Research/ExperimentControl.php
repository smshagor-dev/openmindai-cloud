<?php

namespace App\Services\Research;

use App\Models\Research\Experiment;
use App\Models\Research\Project;

class ExperimentControl
{
    public function cancel(int $project, ?int $experiment = null): array
    {
        $rows = Transactions::run(function () use ($project, $experiment) {
            Project::whereKey($project)->lockForUpdate()->firstOrFail();
            $q = Experiment::where('project_id', $project);
            if ($experiment !== null) {
                $q->whereKey($experiment)->firstOrFail();
            }
            $rows = $q->whereIn('status', ['queued', 'running'])->lockForUpdate()->get();
            foreach ($rows as $x) {
                $x->update(['status' => 'cancelled', 'result' => ['cancellation' => 'requested']]);
            }

            return $rows;
        });
        $unconfirmed = [];
        foreach ($rows as $x) {
            try {
                $response = app(Worker::class)->call('cancel', ['execution_id' => $x->execution_id]);
                if (($response['status'] ?? '') !== 'cancelled' || ($response['execution_id'] ?? '') !== $x->execution_id) {
                    throw new \RuntimeException('cancellation_unconfirmed');
                }
                $state = 'confirmed';
            } catch (\Throwable) {
                $state = 'unconfirmed';
                $unconfirmed[] = $x->id;
            }
            Experiment::whereKey($x->id)->where('status', 'cancelled')->update(['result' => json_encode(['cancellation' => $state]), 'updated_at' => now()]);
            Events::add($project, 'experiment.cancel_'.$state, ['execution_id' => $x->execution_id, 'status' => 'cancelled']);
        }

        return ['status' => 'accepted', 'unconfirmed_experiment_cancellations' => $unconfirmed];
    }
}
