<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\ApiCreditUsage;
use App\Services\AutomaticMemory;
use App\Services\ChatContext;
use App\Services\HostedChatActivity;
use App\Services\WebEvidence;
use App\Services\WebResearch;
use App\Services\WebSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

// Only the Next.js server can prepare/finalize a hosted inference turn.
// Laravel owns authentication, context, rate limits and message persistence.
class FrontendChatController
{
    private function guard(Request $r): void
    {
        $key = (string) config('models.frontend_chat_key');
        abort_unless(strlen($key) >= 32, 503, 'Frontend chat bridge is not configured.');
        abort_unless(hash_equals($key, (string) $r->header('X-Chat-Bridge-Key')), 403);
    }

    public function begin(Request $r, ChatContext $context)
    {
        $this->guard($r);
        $d = $r->validate(['message' => 'required|string|max:16000', 'conversation_id' => 'nullable|integer', 'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $r->user()->id)], 'assistant_id' => ['nullable', 'integer', Rule::exists('assistants', 'id')->where('user_id', $r->user()->id)], 'attachment_ids' => 'sometimes|array|max:5', 'attachment_ids.*' => 'integer', 'temporary' => 'sometimes|boolean', 'web_search' => 'sometimes|boolean', 'deep_research' => 'sometimes|boolean', 'search_time_range' => 'sometimes|in:day,week,month,year', 'history' => 'sometimes|array|max:20', 'history.*.role' => 'required|in:user,assistant', 'history.*.content' => 'required|string|max:16000', 'provider_model' => 'sometimes|string|max:190', 'conversation_tools' => 'sometimes|boolean']);
        if (! empty($d['provider_model'])) {
            app(ApiCreditUsage::class)->connection('nvidia', $d['provider_model'], 'chat');
        }
        $temporary = ! empty($d['temporary']);
        abort_if($temporary && ! empty($d['conversation_id']), 422, 'Temporary chats cannot continue saved conversations.');
        $c = ! empty($d['conversation_id']) ? Conversation::where('user_id', $r->user()->id)->findOrFail($d['conversation_id']) : new Conversation(['user_id' => $r->user()->id, 'title' => mb_substr($d['message'], 0, 80), 'project_id' => $d['project_id'] ?? null, 'assistant_id' => $d['assistant_id'] ?? null]);
        $messages = $context->build($r->user(), $c, $d['message'], $d['attachment_ids'] ?? [], $temporary);
        if ($temporary) {
            $history = [];
            $input = $d['history'] ?? [];
            for ($i = 0; $i < count($input) - 1; $i++) {
                if ($input[$i]['role'] === 'user' && $input[$i + 1]['role'] === 'assistant') {
                    $history[] = $input[$i];
                    $history[] = $input[++$i];
                }
            }
            while (array_sum(array_map(fn ($m) => strlen($m['content']), $history)) > 32000) {
                $history = array_slice($history, 2);
            }
            $messages = [$messages[0], ...$history, $messages[count($messages) - 1]];
        }
        app(HostedChatActivity::class)->recover($r->user()->id);
        $lock = Cache::lock('chat-user:'.$r->user()->id, 86400);
        abort_unless($lock->get(), 409, 'A request is already running.');
        $assistant = null;
        try {
            app(HostedChatActivity::class)->heartbeat($r->user()->id, $lock->owner());
            $research = app(WebResearch::class);
            $deep = ! empty($d['deep_research']);
            $options = ['time_range' => $d['search_time_range'] ?? null];
            $evidence = ! empty($d['conversation_tools']) ? [] : ($deep ? $research->collect($d['message'], $options) : (! empty($d['web_search']) ? $research->quick($d['message'], $options) : []));
            if ($evidence || ! empty($d['web_search'])) {
                $messages[0]['content'] .= $research->prompt($evidence, $deep);
            }
            $sources = $research->summaries($evidence);
            $question = null;
            if (! $temporary) {
                DB::transaction(function () use ($c, $d, $sources, &$question, &$assistant) {
                    $c->save();
                    $question = $c->messages()->create(['role' => 'user', 'content' => $d['message'], 'attachment_ids' => $d['attachment_ids'] ?? []]);
                    $assistant = $c->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'generating', 'sources' => $sources]);
                    $c->touch();
                });
            }
            $ticket = bin2hex(random_bytes(32));
            $assistant?->update(['inference_token' => hash('sha256', $ticket), 'inference_checkpoint' => ['messages' => $messages, 'deep_research' => $deep, 'search_time_range' => $d['search_time_range'] ?? null, 'conversation_tools' => ! empty($d['conversation_tools'])]]);
            Cache::put('frontend-chat:'.hash('sha256', $ticket), ['user_id' => $r->user()->id, 'message_id' => $assistant?->id, 'user_message_id' => $question?->id, 'conversation_id' => $temporary ? null : $c->id, 'lock_owner' => $lock->owner(), 'done' => false, 'deep_research' => $deep, 'conversation_tools' => ! empty($d['conversation_tools'])], 600);

            return ['ticket' => $ticket, 'messages' => $messages, 'conversation_id' => $temporary ? null : $c->id, 'user_message_id' => $question?->id, 'message_id' => $assistant?->id, 'sources' => $sources, 'automatic_memory' => ! $temporary && $r->user()->automatic_memory && $r->user()->memory_enabled];
        } catch (Throwable $e) {
            $assistant?->update(['status' => 'failed']);
            app(HostedChatActivity::class)->finish($r->user()->id, $lock->owner());
            $lock->release();
            throw $e;
        }
    }

    public function checkpoint(Request $r)
    {
        [, $lease] = $this->lease($r);
        abort_if($lease['done'], 409);
        $d = $r->validate(['state' => 'required|array']);
        abort_if(! empty($lease['deep_research']) && strlen($r->getContent()) > 1500000, 413, 'Research checkpoint is too large.');
        if (! $lease['message_id']) {
            return ['stored' => false];
        }

        return DB::transaction(function () use ($r, $lease, $d) {
            $m = Message::whereKey($lease['message_id'])->lockForUpdate()->firstOrFail();
            abort_unless($m->inference_token === hash('sha256', $r->input('ticket')) && $m->status === 'generating', 409);
            $state = $m->inference_checkpoint ?? [];
            $state['progress'] = $d['state'];
            $updates = ['inference_checkpoint' => $state];
            if (is_array($d['state']['metadata'] ?? null)) {
                abort_if(strlen(json_encode($d['state']['metadata'])) > 200000, 413, 'Research metadata is too large.');
                $updates['research_metadata'] = $d['state']['metadata'];
                $updates['sources'] = array_values(array_filter(array_map(fn ($source) => app(WebEvidence::class)->summary($source), array_slice($d['state']['sources'] ?? [], 0, 60))));
            }
            $m->update($updates);

            return ['stored' => true];
        });
    }

    public function resume(Request $r)
    {
        $this->guard($r);
        $d = $r->validate(['message_id' => 'required|integer']);
        $m = Message::whereHas('conversation', fn ($q) => $q->where('user_id', $r->user()->id))->findOrFail($d['message_id']);
        abort_unless($m->role === 'assistant' && $m->resumable, 409, 'This response cannot be resumed.');
        app(HostedChatActivity::class)->recover($r->user()->id);
        $lock = Cache::lock('chat-user:'.$r->user()->id, 86400);
        abort_unless($lock->get(), 409, 'A request is still running. Try again after it stops.');
        try {
            $result = DB::transaction(function () use ($r, $m, $lock) {
                $c = Conversation::whereKey($m->conversation_id)->lockForUpdate()->firstOrFail();
                $m = Message::whereKey($m->id)->lockForUpdate()->firstOrFail();
                abort_unless($m->resumable && (int) $c->messages()->max('id') === $m->id, 409, 'Only the latest interrupted response can resume.');
                $state = $m->inference_checkpoint;
                $ticket = bin2hex(random_bytes(32));
                $question = $c->messages()->where('role', 'user')->where('id', '<', $m->id)->latest('id')->firstOrFail();
                $m->update(['status' => 'generating', 'inference_token' => hash('sha256', $ticket)]);
                Cache::forget('stop-message:'.$m->id);
                Cache::put('frontend-chat:'.hash('sha256', $ticket), ['user_id' => $r->user()->id, 'message_id' => $m->id, 'user_message_id' => $question->id, 'conversation_id' => $c->id, 'lock_owner' => $lock->owner(), 'done' => false, 'deep_research' => $state['deep_research'] ?? false], 600);

                return ['ticket' => $ticket, 'messages' => $state['messages'], 'conversation_id' => $c->id, 'user_message_id' => $question->id, 'message_id' => $m->id, 'sources' => $state['progress']['sources'] ?? $m->sources ?? [], 'checkpoint' => $state['progress'] ?? null, 'deep_research' => $state['deep_research'] ?? false, 'search_time_range' => $state['search_time_range'] ?? null];
            });
            app(HostedChatActivity::class)->heartbeat($r->user()->id, $lock->owner());

            return $result;
        } catch (Throwable $e) {
            $lock->release();
            throw $e;
        }
    }

    private function lease(Request $r): array
    {
        $this->guard($r);
        $d = $r->validate(['ticket' => 'required|string|size:64']);
        $key = 'frontend-chat:'.hash('sha256', $d['ticket']);
        $lease = Cache::get($key);
        abort_unless($lease && $lease['user_id'] === $r->user()->id, 410, 'Chat lease expired.');

        if ($lease['message_id']) {
            if (! Message::whereKey($lease['message_id'])->where('inference_token', hash('sha256', $d['ticket']))->exists()) {
                app(HostedChatActivity::class)->finish($r->user()->id, $lease['lock_owner']);
                Cache::restoreLock('chat-user:'.$r->user()->id, $lease['lock_owner'])->release();
                abort(410, 'Chat attempt was replaced or deleted.');
            }
        }

        return [$key, $lease];
    }

    public function status(Request $r)
    {
        [$key] = $this->lease($r);

        return Cache::lock($key.':finish', 20)->block(3, function () use ($key, $r) {
            $lease = Cache::get($key);
            abort_unless($lease && $lease['user_id'] === $r->user()->id, 410);
            $cancelled = $lease['message_id'] && (! Message::whereKey($lease['message_id'])->where('inference_token', hash('sha256', $r->input('ticket')))->where('status', 'generating')->exists() || Cache::get('stop-message:'.$lease['message_id']));
            if (! $lease['done'] && ! $cancelled) {
                Cache::put($key, $lease, 600);
                app(HostedChatActivity::class)->heartbeat($r->user()->id, $lease['lock_owner']);
                if ($lease['message_id']) {
                    Message::whereKey($lease['message_id'])->where('status', 'generating')->update(['updated_at' => now()]);
                }
            }

            return ['cancelled' => (bool) $cancelled, 'done' => $lease['done']];
        });
    }

    public function tool(Request $r)
    {
        [, $lease] = $this->lease($r);
        abort_unless(! $lease['done'] && (! empty($lease['deep_research']) || ! empty($lease['conversation_tools'])), 409);
        abort_if($lease['message_id'] && (! Message::whereKey($lease['message_id'])->where('status', 'generating')->exists() || Cache::get('stop-message:'.$lease['message_id'])), 409, 'Research stopped.');
        $d = $r->validate(['action' => 'required|in:search,read', 'query' => 'required_if:action,search|string|max:500', 'url' => 'required_if:action,read|string|max:2000', 'time_range' => 'nullable|in:day,week,month,year', 'question' => 'sometimes|string|max:16000']);
        if ($d['action'] === 'search') {
            return ['sources' => app(WebSearch::class)->search($d['query'], ['limit' => 10, 'time_range' => $d['time_range'] ?? null])];
        }
        try {
            $url = WebSearch::canonicalUrl($d['url']);
            abort_unless($url, 422);

            return app(WebEvidence::class)->read($url, $d['question'] ?? '');
        } catch (Throwable $e) {
            return ['url' => $d['url'], 'read_status' => 'unavailable'];
        }
    }

    public function remember(Request $r)
    {
        [, $lease] = $this->lease($r);
        abort_unless($lease['done'] && ($lease['status'] ?? '') === 'complete' && $lease['message_id'], 409);
        $answer = Message::whereKey($lease['message_id'])->whereHas('conversation', fn ($q) => $q->where('user_id', $r->user()->id))->firstOrFail();
        abort_unless($answer->status === 'complete', 409);
        $d = $r->validate(['memories' => 'present|array|max:3', 'memories.*' => 'required|string|max:500']);

        return ['saved' => app(AutomaticMemory::class)->remember($r->user(), $d['memories'])];
    }

    public function finish(Request $r)
    {
        [$key] = $this->lease($r);
        $d = $r->validate(['content' => 'present|nullable|string', 'sources' => 'sometimes|array', 'sources.*.url' => 'required|string|max:2000', 'sources.*.title' => 'present|nullable|string|max:200', 'sources.*.snippet' => 'sometimes|nullable|string|max:2000', 'sources.*.read_status' => 'sometimes|in:read,unavailable,snippet_only', 'sources.*.citation' => 'sometimes|integer|min:1', 'research_metadata' => 'sometimes|array', 'status' => 'required|in:complete,failed,cancelled', 'provider_model' => 'sometimes|string|max:190']);
        // Preserve provider whitespace after validating the submitted field type.
        $d['content'] = json_decode($r->getContent(), true)['content'] ?? '';
        if (! is_string($d['content'])) {
            $d['content'] = '';
        }
        abort_if($d['status'] === 'complete' && trim($d['content']) === '', 422, 'Completed answer is empty.');

        return Cache::lock($key.':finish', 20)->block(3, function () use ($key, $r, $d) {
            $lease = Cache::get($key);
            abort_unless($lease && $lease['user_id'] === $r->user()->id, 410);
            if ($lease['done']) {
                return ['stored' => true, 'status' => $lease['status']];
            }
            if ($lease['message_id']) {
                $m = Message::whereKey($lease['message_id'])->whereHas('conversation', fn ($q) => $q->where('user_id', $r->user()->id))->first();
                // Message ownership is checked via its conversation below; deletion is never undone.
                if ($m) {
                    $updates = ['content' => $d['content'], 'status' => $d['status']];
                    if ($d['status'] === 'complete') {
                        $updates['inference_checkpoint'] = null;
                    }
                    if (isset($d['sources'])) {
                        $updates['sources'] = array_values(array_filter(array_map(fn ($source) => app(WebEvidence::class)->summary($source), array_slice($d['sources'], 0, 60))));
                    }
                    if (isset($d['research_metadata'])) {
                        abort_if(strlen(json_encode($d['research_metadata'])) > 200000, 413, 'Research metadata is too large.');
                        $updates['research_metadata'] = $d['research_metadata'];
                    }
                    $m->fill($updates);
                    abort_unless(Message::whereKey($m->id)->where('inference_token', hash('sha256', $r->input('ticket')))->update($m->getDirty()), 410);
                }
            }
            if ($d['status'] === 'complete' && ! empty($d['provider_model'])) {
                $connection = app(ApiCreditUsage::class)->connection('nvidia', $d['provider_model'], 'chat');
                app(ApiCreditUsage::class)->completed($r->user()->id, $connection, 'chat:'.hash('sha256', $d['ticket'] ?? $r->input('ticket')));
            }
            $lease['done'] = true;
            $lease['status'] = $d['status'];
            Cache::put($key, $lease, 600);
            app(HostedChatActivity::class)->finish($r->user()->id, $lease['lock_owner']);
            Cache::restoreLock('chat-user:'.$r->user()->id, $lease['lock_owner'])->release();

            return ['stored' => true, 'status' => $d['status']];
        });
    }
}
