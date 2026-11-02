<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Research\Document;
use App\Models\Research\Project;
use App\Models\Research\Run;
use App\Models\Research\Source;
use App\Models\Research\Task;
use App\Services\Research\Blueprint;
use App\Services\Research\Orchestrator;
use Illuminate\Support\Facades\DB;

class ChatBlueprint
{
    public function handle(int $messageId, int $owner, string $action, string $question = ''): array
    {
        $project = DB::transaction(function () use ($messageId, $owner, $action, $question) {
            $message = Message::whereKey($messageId)->whereHas('conversation', fn ($q) => $q->where('user_id', $owner))->lockForUpdate()->firstOrFail();
            $state = $message->inference_checkpoint ?? [];
            $id = $state['blueprint_project_id'] ?? null;
            if ($id) {
                return Project::whereKey($id)->where('owner_id', $owner)->firstOrFail();
            }
            abort_unless($action === 'start' && trim($question) !== '', 409, 'Start the web search first.');
            abort_unless(app(Blueprint::class)->configured(), 503, 'NVIDIA Deep Researcher runtime is unavailable.');
            abort_if(Project::where('owner_id', $owner)->count() >= 100, 422, 'Research project limit reached.');
            $project = Project::create(['owner_id' => $owner, 'title' => 'Web search · '.mb_substr($question, 0, 170), 'prompt' => $question, 'settings' => ['output_language' => 'auto', 'progress_language' => 'auto', 'chat_message_id' => $messageId, 'from_year' => 1900, 'to_year' => now()->year]]);
            Document::create(['project_id' => $project->id, 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]);
            app(Orchestrator::class)->start($project, $owner, 'nvidia', ['web_search' => true]);
            $state['blueprint_project_id'] = $project->id;
            $message->update(['inference_checkpoint' => $state]);

            return $project;
        });
        if ($action === 'cancel') {
            app(Orchestrator::class)->control($project, 'cancel');
        }
        $run = Run::where('project_id', $project->id)->latest('id')->firstOrFail();
        $task = Task::where('run_id', $run->id)->where('agent', 'nvidia')->firstOrFail();
        $sources = Source::where('project_id', $project->id)->whereNotNull('url')->orderBy('id')->limit(60)->get()->map(function ($s, $index) {
            $read = $s->content_hash && trim((string) $s->text) !== '';

            return ['url' => $s->url, 'title' => mb_substr($s->title ?: $s->url, 0, 200), 'citation' => $index + 1, 'provider' => 'nvidia-blueprint-web', 'read_status' => $read ? 'read' : 'snippet_only', 'snippet' => mb_substr((string) $s->text, 0, 2000), 'retrieved_at' => $s->updated_at->toIso8601String(), 'passages' => $read ? collect(mb_str_split(mb_substr((string) $s->text, 0, 8000), 1000))->map(fn ($text, $i) => ['id' => 'p'.($i + 1), 'text' => $text])->all() : []];
        })->all();

        return ['project_id' => $project->id, 'status' => $task->status === 'completed' ? 'completed' : $run->status, 'task_status' => $task->status, 'report' => $task->status === 'completed' ? ($task->output['report'] ?? '') : '', 'sources' => $sources, 'engine' => 'nvidia-deep-researcher-2.2'];
    }
}
