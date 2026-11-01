<?php

namespace App\Http\Controllers;

use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\Project;
use App\Services\ChatContext;
use App\Services\HostedChatActivity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class ChatController
{
    public function models()
    {
        return AiModel::where('enabled', true)->where('status', 'ready')->get(['id', 'name', 'family', 'license']);
    }

    public function index(Request $r)
    {
        $r->validate(['q' => 'sometimes|nullable|string|max:100', 'project_id' => 'sometimes|integer', 'archived' => 'sometimes|boolean']);
        $q = Conversation::where('user_id', $r->user()->id);
        if ($r->boolean('archived')) {
            $q->whereNotNull('archived_at');
        } else {
            $q->whereNull('archived_at');
        }
        if ($r->filled('project_id')) {
            $q->where('project_id', (int) $r->input('project_id'));
        }
        if ($r->filled('q')) {
            $term = '%'.mb_substr($r->input('q'), 0, 100).'%';
            $q->where(fn ($q) => $q->where('title', 'like', $term)->orWhereHas('messages', fn ($m) => $m->where('content', 'like', $term)));
        }

        return $q->orderByDesc('pinned')->latest('updated_at')->latest('id')->paginate(20);
    }

    public function show(Request $r, int $id)
    {
        $conversation = Conversation::where('user_id', $r->user()->id)->findOrFail($id);

        return ['conversation' => $conversation, 'messages' => $conversation->messages()->orderBy('id')->paginate(50)];
    }

    public function delete(Request $r, int $id)
    {
        Conversation::where('user_id', $r->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function send(Request $r)
    {
        $d = $r->validate(['model_id' => 'required|integer', 'assistant_id' => ['nullable', 'integer', Rule::exists('assistants', 'id')->where('user_id', $r->user()->id)], 'conversation_id' => 'nullable|integer', 'message' => 'required|string|max:16000', 'max_tokens' => 'sometimes|integer|min:1', 'temporary' => 'sometimes|boolean', 'attachment_ids' => 'sometimes|array|max:5', 'attachment_ids.*' => 'integer', 'project_id' => 'nullable|integer']);
        abort_if(! empty($d['temporary']) && ! empty($d['conversation_id']), 422, 'Temporary chats cannot continue saved conversations.');
        $model = AiModel::where('enabled', true)->where('status', 'ready')->findOrFail($d['model_id']);
        abort_unless($model->runtime_model, 503, 'Model is not connected to inference.');
        app(HostedChatActivity::class)->recover($r->user()->id);
        $lock = Cache::lock('chat-user:'.$r->user()->id, 150);
        abort_unless($lock->get(), 409, 'A request is already running.');
        try {
            $c = isset($d['conversation_id']) ? Conversation::where('user_id', $r->user()->id)->findOrFail($d['conversation_id']) : null;
            if (! empty($d['project_id'])) {
                Project::where('user_id', $r->user()->id)->findOrFail($d['project_id']);
            }
            $history = app(ChatContext::class)->build($r->user(), $c ?? new Conversation(['project_id' => $d['project_id'] ?? null, 'assistant_id' => $d['assistant_id'] ?? null]), $d['message'], $d['attachment_ids'] ?? [], ! empty($d['temporary']));
            try {
                $response = Http::withToken((string) config('models.inference_key'))->acceptJson()->connectTimeout(10)->timeout(600)->withOptions(['allow_redirects' => false])->post(rtrim(config('models.inference_url'), '/').'/chat/completions', ['model' => $model->runtime_model, 'messages' => $history, ...(isset($d['max_tokens']) ? ['max_tokens' => $d['max_tokens']] : []), 'stream' => false]);
            } catch (ConnectionException $e) {
                abort(503, 'Inference server unavailable.');
            }
            abort_unless($response->successful(), 502, 'Inference request failed.');
            $content = $response->json('choices.0.message.content');
            abort_unless(is_string($content) && trim($content) !== '' && strlen($content) <= 262144, 502, 'Invalid inference response.');
            if (! empty($d['temporary'])) {
                return ['conversation_id' => null, 'message' => ['role' => 'assistant', 'content' => $content]];
            }
            $c = DB::transaction(function () use ($c, $r, $d, $content) {
                $c ??= Conversation::create(['user_id' => $r->user()->id, 'title' => mb_substr($d['message'], 0, 80), 'project_id' => $d['project_id'] ?? null, 'assistant_id' => $d['assistant_id'] ?? null]);
                $c->messages()->createMany([['role' => 'user', 'content' => $d['message'], 'attachment_ids' => $d['attachment_ids'] ?? []], ['role' => 'assistant', 'content' => $content]]);
                $c->touch();

                return $c;
            });

            return ['conversation_id' => $c->id, 'message' => ['role' => 'assistant', 'content' => $content]];
        } finally {
            $lock->release();
        }
    }
}
