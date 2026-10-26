<?php

namespace App\Http\Controllers;

use App\Jobs\RememberConversation;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Services\ChatContext;
use App\Services\HostedChatActivity;
use App\Services\ResearchCompletion;
use App\Services\WebEvidence;
use App\Services\WebResearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Throwable;

class StreamChatController
{
    public function __invoke(Request $r, ChatContext $context)
    {
        $d = $r->validate(['model_id' => 'required|integer', 'assistant_id' => ['nullable', 'integer', Rule::exists('assistants', 'id')->where('user_id', $r->user()->id)], 'message' => 'required|string|max:16000', 'conversation_id' => 'nullable|integer', 'project_id' => 'nullable|integer', 'temporary' => 'sometimes|boolean', 'attachment_ids' => 'sometimes|array|max:5', 'attachment_ids.*' => 'integer', 'web_search' => 'sometimes|boolean', 'deep_research' => 'sometimes|boolean', 'search_time_range' => 'sometimes|in:day,week,month,year', 'history' => 'sometimes|array|max:20', 'history.*.role' => 'required|in:user,assistant', 'history.*.content' => 'required|string|max:16000']);
        $model = AiModel::where('enabled', true)->where('status', 'ready')->findOrFail($d['model_id']);
        abort_unless($model->runtime_model, 503, 'No inference runtime configured.');
        $temporary = ! empty($d['temporary']);
        abort_if($temporary && ! empty($d['conversation_id']), 422, 'Temporary chats cannot continue saved conversations.');
        $c = ! empty($d['conversation_id']) ? Conversation::where('user_id', $r->user()->id)->findOrFail($d['conversation_id']) : null;
        if (! empty($d['project_id'])) {
            Project::where('user_id', $r->user()->id)->findOrFail($d['project_id']);
        }
        if (! $c && ! $temporary) {
            $c = new Conversation(['user_id' => $r->user()->id, 'title' => mb_substr($d['message'], 0, 80), 'project_id' => $d['project_id'] ?? null, 'assistant_id' => $d['assistant_id'] ?? null]);
        }
        if ($temporary && ! empty($d['assistant_id'])) {
            $c = new Conversation(['assistant_id' => $d['assistant_id']]);
        }
        $research = app(WebResearch::class);
        $deep = ! empty($d['deep_research']);
        $options = ['time_range' => $d['search_time_range'] ?? null];
        $evidence = $deep ? [] : (! empty($d['web_search']) ? $research->quick($d['message'], $options) : []);
        $sources = $research->summaries($evidence);
        $messages = $context->build($r->user(), $c, $d['message'], $d['attachment_ids'] ?? [], $temporary);
        if ($evidence || ! empty($d['web_search'])) {
            $messages[0]['content'] .= $research->prompt($evidence, $deep);
        }
        if ($temporary && ! empty($d['history'])) {
            $h = [];
            $input = $d['history'];
            for ($i = 0; $i < count($input) - 1; $i++) {
                if ($input[$i]['role'] === 'user' && $input[$i + 1]['role'] === 'assistant') {
                    $h[] = ['role' => 'user', 'content' => $input[$i]['content']];
                    $h[] = ['role' => 'assistant', 'content' => $input[$i + 1]['content']];
                    $i++;
                }
            }
            while (array_sum(array_map(fn ($m) => strlen($m['content']), $h)) > 32000) {
                $h = array_slice($h, 2);
            }$messages = [$messages[0], ...$h, $messages[count($messages) - 1]];
        }
        app(HostedChatActivity::class)->recover($r->user()->id);
        $lock = Cache::lock('chat-user:'.$r->user()->id, 86400);
        abort_unless($lock->get(), 409, 'A request is already running.');
        try {

            app(HostedChatActivity::class)->heartbeat($r->user()->id, $lock->owner());

            $saved = null;
            $userMessage = null;
            if (! $temporary) {
                DB::transaction(function () use (&$saved, &$userMessage, $c, $d, $sources) {
                    $c->save();
                    $userMessage = $c->messages()->create(['role' => 'user', 'content' => $d['message'], 'attachment_ids' => $d['attachment_ids'] ?? []]);
                    $saved = $c->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'generating', 'sources' => $sources]);
                    $c->touch();
                });
            }
        } catch (Throwable $e) {

            app(HostedChatActivity::class)->finish($r->user()->id, $lock->owner());

            $lock->release();
            throw $e;
        }

        return response()->stream(function () use ($messages, $model, $saved, $userMessage, $c, $temporary, $lock, $sources, $deep, $d, $r, $evidence) {
            ignore_user_abort(true);
            set_time_limit(0);
            $text = '';
            $status = 'complete';
            $metadata = ['version' => 1, 'status' => 'running', 'plan' => [], 'activity' => []];
            $finished = false;
            $body = null;
            $emit = function ($event, $data) {
                echo 'event: '.$event."\n".'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                if (! app()->runningUnitTests()) {
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }flush();
                }
            };
            try {
                $emit('meta', ['conversation_id' => $temporary ? null : $c->id, 'user_message_id' => $userMessage?->id, 'message_id' => $saved?->id, 'sources' => $sources]);
                if ($deep) {
                    $coverage = app(ResearchCompletion::class)->run($d['message'], $evidence, $model->runtime_model, function ($label, $found, $event = null) use (&$metadata, &$sources, $saved, $emit, $r, $lock) {
                        $sources = app(WebResearch::class)->summaries($found);
                        if ($event) {
                            $metadata['activity'][] = $event;
                            $metadata['activity'] = array_slice($metadata['activity'], -100);
                        }
                        if ($saved) {
                            Message::whereKey($saved->id)->where('status', 'generating')->update(['sources' => $sources, 'research_metadata' => $metadata, 'updated_at' => now()]);
                        }
                        app(HostedChatActivity::class)->heartbeat($r->user()->id, $lock->owner());
                        $emit('research', ['label' => $label, 'sources' => $sources, 'metadata' => $metadata]);
                    }, fn () => connection_aborted() || ($saved && Cache::get('stop-message:'.$saved->id)));
                    $sources = app(WebResearch::class)->summaries($coverage['sources']);
                    $metadata['limitations'] = empty($coverage['complete']) ? [$coverage['conclusion'], 'Coverage is partial; inspect the evidence and ask a focused follow-up for unresolved questions.'] : [];
                    $metadata['stop_reason'] = $coverage['stop_reason'] ?? null;
                    $metadata['plan'] = array_map(fn ($text, $i) => ['id' => 'o'.($i + 1), 'text' => $text, 'status' => 'unresolved', 'source_ids' => []], $coverage['plan'] ?? [], array_keys($coverage['plan'] ?? []));
                    $messages[0]['content'] .= app(WebResearch::class)->prompt($coverage['sources']).'\nCoverage review: '.$coverage['conclusion'];
                }
                $previousPart = '';
                do {
                    app(HostedChatActivity::class)->heartbeat($r->user()->id, $lock->owner());
                    $finishReason = null;
                    $finished = false;
                    $before = strlen($text);
                    $upstream = Http::withToken((string) config('models.inference_key'))->accept('text/event-stream')->connectTimeout(10)->timeout(120)->withOptions(['stream' => true, 'read_timeout' => 20, 'allow_redirects' => false])->post(rtrim(config('models.inference_url'), '/').'/chat/completions', ['model' => $model->runtime_model, 'messages' => $messages, 'stream' => true]);
                    if (! $upstream->successful()) {
                        throw new \RuntimeException('Inference failed.');
                    }
                    $body = $upstream->toPsrResponse()->getBody();
                    $buffer = '';
                    $started = microtime(true);
                    while (! $body->eof()) {
                        if (microtime(true) - $started > 120) {
                            throw new \RuntimeException('Inference timeout.');
                        }
                        if (connection_aborted() || ($saved && Cache::get('stop-message:'.$saved->id))) {
                            $status = 'cancelled';
                            break;
                        }
                        $buffer .= $body->read(1024);
                        if (strlen($buffer) > 1048576) {
                            throw new \RuntimeException('Invalid inference frame.');
                        }
                        while (($end = strpos($buffer, "\n")) !== false) {
                            $line = trim(substr($buffer, 0, $end));
                            $buffer = substr($buffer, $end + 1);
                            if (! str_starts_with($line, 'data:')) {
                                continue;
                            }$payload = trim(substr($line, 5));
                            if ($payload === '[DONE]') {
                                $finished = true;
                                break 2;
                            }$data = json_decode($payload, true);
                            if (isset($data['error'])) {
                                throw new \RuntimeException('Inference error.');
                            }$delta = $data['choices'][0]['delta']['content'] ?? '';
                            if (is_string($delta) && $delta !== '') {
                                $text .= $delta;
                                $emit('delta', ['content' => $delta]);
                            }if (isset($data['choices'][0]['finish_reason'])) {
                                $finishReason = $data['choices'][0]['finish_reason'];
                                $finished = true;
                            }
                        }
                    }
                    if ($status === 'complete' && (! $finished || $text === '')) {
                        throw new \RuntimeException('Incomplete model response.');
                    }
                    $body?->close();
                    if ($status === 'cancelled') {
                        break;
                    }
                    if ($finishReason === 'length') {
                        $part = substr($text, $before);
                        if ($part === '' || $part === $previousPart) {
                            throw new \RuntimeException('Continuation made no progress.');
                        }
                        $previousPart = $part;
                        $messages[] = ['role' => 'assistant', 'content' => $part];
                        $messages[] = ['role' => 'user', 'content' => 'Continue exactly where the answer stopped. Complete every remaining part of the original request without repeating earlier text.'];
                        if ($saved) {
                            Message::whereKey($saved->id)->where('status', 'generating')->update(['content' => $text, 'updated_at' => now()]);
                        }
                    } elseif ($finishReason && $finishReason !== 'stop') {
                        throw new \RuntimeException('Runtime stopped before completion.');
                    }
                } while ($finishReason === 'length');
            } catch (Throwable $e) {
                $status = connection_aborted() || ($saved && Cache::get('stop-message:'.$saved->id)) ? 'cancelled' : 'failed';
                $emit('error', ['message' => 'The model response was interrupted. Your saved chat contains the partial result.']);
            } finally {
                $body?->close();
                if ($status === 'complete' && ($sources || $deep)) {
                    $grounded = app(WebEvidence::class)->ground($text, $sources);
                    $text = $grounded['text'];
                    $emit('answer', ['content' => $text]);
                }
                $metadata['status'] = $status === 'complete' ? ((! empty($metadata['limitations']) || ! empty($metadata['plan'])) ? 'partial' : 'complete') : $status;
                if ($sources || $deep) {
                    $emit('research', ['label' => 'Research '.$metadata['status'], 'sources' => $sources, 'metadata' => $metadata]);
                }
                if ($saved) {
                    Message::whereKey($saved->id)->update(['content' => $text, 'status' => $status, 'sources' => $sources, 'research_metadata' => $sources || $deep ? $metadata : null]);
                    $c->touch();
                    if ($status === 'complete' && $r->user()->automatic_memory && $r->user()->memory_enabled) {
                        try {
                            RememberConversation::dispatch($userMessage->id, $saved->id, $model->id)->onQueue('tasks');
                        } catch (Throwable $e) { /* Memory is optional; always release the chat lock. */
                        }
                    }
                    Cache::forget('stop-message:'.$saved->id);
                }

                app(HostedChatActivity::class)->finish($r->user()->id, $lock->owner());

                $lock->release();
                $emit('done', ['conversation_id' => $temporary ? null : $c->id, 'message_id' => $saved?->id, 'status' => $status]);
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }
}
