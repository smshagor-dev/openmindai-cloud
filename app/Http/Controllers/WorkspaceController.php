<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Models\Attachment;
use App\Models\Canvas;
use App\Models\ConnectorAccount;
use App\Models\ConnectorCall;
use App\Models\Conversation;
use App\Models\ConversationShare;
use App\Models\MediaTask;
use App\Models\Memory;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Services\UserSettings;
use App\Services\WebSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WorkspaceController
{
    public function search(Request $r)
    {
        $d = $r->validate(['query' => 'required|string|max:500', 'time_range' => 'sometimes|in:day,week,month,year']);

        return ['results' => app(WebSearch::class)->search($d['query'], ['time_range' => $d['time_range'] ?? null])];
    }

    public function capabilities()
    {
        return ['teams' => true, 'connectors' => true, 'live_voice' => (bool) (config('media.live') && config('media.url') && config('media.key') && in_array('transcription', config('media.kinds'))), 'streaming' => true, 'projects' => true, 'memory' => true, 'attachments' => ['text', 'markdown', 'csv', 'json', 'source-code'], 'media' => array_values(array_unique(array_merge(config('media.url') && config('media.key') ? array_values(array_intersect(config('media.kinds'), ['image', 'vision', 'transcription', 'speech', 'video', 'document', 'code'])) : [], (config('cloud_images.enabled') && strlen((string) config('cloud_images.key')) >= 12) ? ['image'] : []))), 'image_generation' => (bool) ((config('cloud_images.enabled') && strlen((string) config('cloud_images.key')) >= 12) || (config('media.url') && config('media.key') && in_array('image', config('media.kinds')))), 'voice_transcription' => (bool) (config('media.url') && config('media.key') && in_array('transcription', config('media.kinds'))), 'web_search' => (bool) (config('models.search_url') || config('models.public_search_fallback') || config('web_research.brave_key') || (config('web_research.google_key') && config('web_research.google_cx'))), 'deep_research' => (bool) (config('models.search_url') || config('models.public_search_fallback') || config('web_research.brave_key') || (config('web_research.google_key') && config('web_research.google_cx'))), 'code_execution' => (bool) (config('media.url') && config('media.key') && in_array('code', config('media.kinds')))];
    }

    public function preferences(Request $r)
    {
        return $r->user()->only(UserSettings::FIELDS);
    }

    public function savePreferences(Request $r)
    {
        $d = $r->validate(UserSettings::rules());
        $r->user()->forceFill($d)->save();

        return $this->preferences($r);
    }

    public function projects(Request $r)
    {
        return Project::where('user_id', $r->user()->id)->latest()->get();
    }

    public function saveProject(Request $r, ?int $id = null)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'instructions' => 'nullable|string|max:8000']);
        $p = $id ? Project::where('user_id', $r->user()->id)->findOrFail($id) : new Project(['user_id' => $r->user()->id]);
        $p->fill($d)->save();

        return $p;
    }

    public function deleteProject(Request $r, int $id)
    {
        Project::where('user_id', $r->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function memories(Request $r)
    {
        return Memory::where('user_id', $r->user()->id)->latest()->get();
    }

    public function saveMemory(Request $r, ?int $id = null)
    {
        $d = $r->validate(['content' => 'required|string|max:2000']);
        abort_if(! $id && Memory::where('user_id', $r->user()->id)->count() >= 100, 422, 'Memory limit reached.');
        $m = $id ? Memory::where('user_id', $r->user()->id)->findOrFail($id) : new Memory(['user_id' => $r->user()->id]);
        $m->fill($d)->save();

        return $m;
    }

    public function deleteMemory(Request $r, int $id)
    {
        Memory::where('user_id', $r->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function updateConversation(Request $r, int $id)
    {
        $c = Conversation::where('user_id', $r->user()->id)->findOrFail($id);
        $d = $r->validate(['title' => 'sometimes|required|string|max:120', 'pinned' => 'sometimes|boolean', 'archived' => 'sometimes|boolean', 'project_id' => 'sometimes|nullable|integer']);
        if (isset($d['project_id'])) {
            Project::where('user_id', $r->user()->id)->findOrFail($d['project_id']);
        }if (array_key_exists('archived', $d)) {
            $d['archived_at'] = $d['archived'] ? now() : null;
            unset($d['archived']);
        }$c->update($d);

        return $c;
    }

    public function feedback(Request $r, int $id, int $message)
    {
        $c = Conversation::where('user_id', $r->user()->id)->findOrFail($id);
        $m = $c->messages()->where('role', 'assistant')->findOrFail($message);
        $d = $r->validate(['feedback' => 'nullable|in:up,down']);
        $m->update($d);

        return $m;
    }

    public function branch(Request $r, int $id)
    {
        $source = Conversation::where('user_id', $r->user()->id)->findOrFail($id);
        $d = $r->validate(['message_id' => 'required|integer']);
        $target = $source->messages()->where('role', 'user')->findOrFail($d['message_id']);

        return DB::transaction(function () use ($source, $target, $r) {
            $copy = Conversation::create(['user_id' => $r->user()->id, 'title' => $source->title.' (branch)', 'project_id' => $source->project_id, 'assistant_id' => $source->assistant_id, 'parent_id' => $source->id]);
            foreach ($source->messages()->where('id', '<', $target->id)->where('status', 'complete')->orderBy('id')->cursor() as $m) {
                $copy->messages()->create(['role' => $m->role, 'content' => $m->content, 'attachment_ids' => $m->attachment_ids]);
            }

            return ['conversation_id' => $copy->id, 'message' => $target->content, 'attachment_ids' => $target->attachment_ids ?? []];
        });
    }

    public function attach(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:160', 'content' => 'required|string|max:50000']);
        abort_unless(preg_match('/\.(txt|md|csv|json|py|js|jsx|ts|tsx|php|rs|go|java|c|cpp|h|html|css|sql|yaml|yml|xml|sh|log)$/i', $d['name']), 422, 'Upload a text, code, CSV, JSON or Markdown file.');
        abort_if(str_contains($d['content'], "\0") || strlen($d['content']) > 100000, 422, 'Invalid text file or file too large.');
        abort_if(Attachment::where('user_id', $r->user()->id)->sum('bytes') + strlen($d['content']) > 10000000, 422, 'Attachment storage quota reached.');

        return Attachment::create(['user_id' => $r->user()->id, 'name' => basename($d['name']), 'content' => $d['content'], 'bytes' => strlen($d['content']), 'mime' => 'text/plain']);
    }

    public function attachment(Request $r, int $id)
    {
        return Attachment::where('user_id', $r->user()->id)->findOrFail($id)->makeVisible('content');
    }

    public function attachments(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:100']);
        $q = Attachment::where('user_id', $r->user()->id);
        if ($r->filled('q')) {
            $q->where('name', 'like', '%'.$r->query('q').'%');
        }

        return $q->latest()->paginate(30);
    }

    public function deleteAttachment(Request $r, int $id)
    {
        Attachment::where('user_id', $r->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function share(Request $r, int $id)
    {
        $c = Conversation::where('user_id', $r->user()->id)->findOrFail($id);
        abort_if($c->messages()->count() > 500, 422, 'Share a smaller conversation.');
        $snapshot = ['title' => $c->title, 'messages' => $c->messages()->where('status', 'complete')->orderBy('id')->get(['role', 'content'])->all()];
        abort_if(strlen(json_encode($snapshot)) > 3000000, 422, 'Conversation is too large to share.');
        $token = bin2hex(random_bytes(32));
        $share = ConversationShare::create(['user_id' => $r->user()->id, 'conversation_id' => $id, 'token_hash' => hash('sha256', $token), 'snapshot' => $snapshot, 'expires_at' => now()->addDays(30)]);

        return ['id' => $share->id, 'token' => $token, 'expires_at' => $share->expires_at];
    }

    public function shares(Request $r)
    {
        return ConversationShare::where('user_id', $r->user()->id)->latest()->get(['id', 'conversation_id', 'created_at', 'expires_at']);
    }

    public function revokeShare(Request $r, int $id)
    {
        ConversationShare::where('user_id', $r->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function shared(string $token)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $share = ConversationShare::where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->firstOrFail();

        return $share->snapshot;
    }

    public function export(Request $r)
    {
        return ['team_memberships' => DB::table('team_members')->join('teams', 'teams.id', '=', 'team_members.team_id')->where('team_members.user_id', $r->user()->id)->get(['teams.id', 'teams.name', 'team_members.role']), 'team_posts' => DB::table('team_posts')->where('user_id', $r->user()->id)->get(), 'connector_accounts' => ConnectorAccount::where('user_id', $r->user()->id)->get(['server', 'created_at', 'updated_at']), 'connector_calls' => ConnectorCall::where('user_id', $r->user()->id)->where('status', '!=', 'deleted')->get(), 'canvas_versions' => DB::table('canvas_versions')->whereIn('canvas_id', Canvas::where('user_id', $r->user()->id)->select('id'))->get(), 'assistants' => Assistant::where('user_id', $r->user()->id)->get(), 'scheduled_tasks' => ScheduledTask::where('user_id', $r->user()->id)->get(), 'task_runs' => TaskRun::whereIn('scheduled_task_id', ScheduledTask::where('user_id', $r->user()->id)->select('id'))->get(), 'media_tasks' => MediaTask::where('user_id', $r->user()->id)->get(), 'canvases' => Canvas::where('user_id', $r->user()->id)->get(), 'preferences' => $this->preferences($r), 'exported_at' => now()->toIso8601String(), 'user' => $r->user()->only('name', 'email', 'avatar', 'custom_instructions', 'memory_enabled'), 'projects' => Project::where('user_id', $r->user()->id)->get(), 'memories' => Memory::where('user_id', $r->user()->id)->get(), 'conversations' => Conversation::where('user_id', $r->user()->id)->with('messages')->get(), 'attachments' => Attachment::where('user_id', $r->user()->id)->get()->makeVisible('content')];
    }

    public function stop(Request $r, int $id, int $message)
    {
        $c = Conversation::where('user_id', $r->user()->id)->findOrFail($id);
        $m = $c->messages()->where('role', 'assistant')->where('status', 'generating')->findOrFail($message);
        Cache::put('stop-message:'.$m->id, true, 180);

        return ['message' => 'Stop requested.'];
    }
}
