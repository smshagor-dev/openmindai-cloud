<?php

namespace App\Http\Controllers;

use App\Models\Research\Source;
use App\Models\Research\Task;
use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\HostedCodeAgents;
use App\Services\Research\Context;
use App\Services\Research\Scholarly;
use App\Services\WebReader;
use App\Services\WebSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ResearchBlueprintBridgeController
{
    private function context(Request $request): array
    {
        abort_if(strlen($request->getContent()) > 1048576, 413, 'Research bridge payload is too large.');
        try {
            $claims = json_decode(Crypt::decryptString((string) $request->bearerToken()), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(401, 'Invalid research task token.');
        }
        abort_unless(is_array($claims) && isset($claims['task_id'],$claims['claim'],$claims['actor_id'],$claims['model'],$claims['expires']) && (int) $claims['expires'] >= now()->timestamp, 401, 'Expired research task token.');
        $task = Task::findOrFail($claims['task_id']);
        abort_unless($task->agent === 'nvidia' && $task->claim !== null && hash_equals($task->claim, $claims['claim']), 409, 'Research execution is no longer active.');
        $ctx = new Context($task, $claims['claim']);
        $ctx->fenced(fn () => null);
        abort_unless($ctx->run()->actor_id === $claims['actor_id'], 401);

        return [$ctx, $claims];
    }

    public function models(Request $request)
    {
        [$ctx,$claims] = $this->context($request);

        return ['object' => 'list', 'data' => [['id' => $claims['model'], 'object' => 'model', 'owned_by' => 'nvidia']]];
    }

    public function completion(Request $request, HostedCodeAgents $agents, ApiCreditUsage $credits)
    {
        [$ctx,$claims] = $this->context($request);
        $data = $request->json()->all();
        abort_unless(($data['model'] ?? null) === $claims['model'], 403, 'Model is outside the active research scope.');
        $model = DB::table('hosted_code_agents')->where('provider_model', $claims['model'])->where('enabled', true)->where('api_ready', true)->where('api_protocol', 'chat')->where('last_test_status', 'success')->first();
        abort_unless($model, 503, 'Research model is unavailable.');
        $data['model'] = $model->slug;
        unset($data['max_tokens'],$data['max_completion_tokens']);
        $user = User::findOrFail($claims['actor_id']);
        $token = new PersonalAccessToken;
        $token->forceFill(['abilities' => ['user']]);
        $user->withAccessToken($token);
        $r = Request::create('/api/v1/mobile/chat/completions', 'POST', $data);
        $r->setUserResolver(fn () => $user);
        $r->attributes->set('blueprint_context', true);
        $r->attributes->set('research_literature_json', true);
        $ctx->event('blueprint.inference', ['model' => $model->slug, 'status' => 'running', 'stream' => (bool) ($data['stream'] ?? false), 'tool_count' => count($data['tools'] ?? [])]);

        try {
            $response = app(OpenMindAiGatewayController::class)->mobileChat($r, $agents, $credits);
        } catch (ValidationException $error) {
            Log::warning('research.blueprint.request_rejected', ['status' => 422, 'fields' => array_slice(array_keys($error->validator->failed()), 0, 32)]);
            throw $error;
        } catch (HttpExceptionInterface $error) {
            Log::warning('research.blueprint.request_rejected', ['status' => $error->getStatusCode(), 'exception_class' => get_class($error)]);
            throw $error;
        }
        if ($response instanceof JsonResponse) {
            $body = $response->getData(true);
            $choice = $body['choices'][0] ?? [];
            $ctx->event('blueprint.inference.completed', ['status_code' => $response->getStatusCode(), 'finish_reason' => $choice['finish_reason'] ?? null, 'tool_count' => count($choice['message']['tool_calls'] ?? []), 'content_characters' => is_string($choice['message']['content'] ?? null) ? mb_strlen($choice['message']['content']) : 0]);
        }

        return $response;
    }

    public function sources(Request $request, WebSearch $search, WebReader $reader)
    {
        [$ctx] = $this->context($request);
        $data = $request->validate(['operation' => 'required|in:search,papers,read', 'query' => 'required|string|max:2000']);
        $project = $ctx->task->project_id;
        $rows = [];
        if ($data['operation'] === 'papers') {
            $settings = $ctx->project()->settings;
            $ids = app(Scholarly::class)->search($ctx, mb_substr($data['query'], 0, 300), 'crossref', (int) ($settings['from_year'] ?? now()->year - 3), (int) ($settings['to_year'] ?? now()->year));
            $rows = Source::where('project_id', $project)->whereIn('id', $ids)->get()->map(fn ($s) => ['id' => $s->id, 'url' => $s->url ?: ($s->doi ? 'https://doi.org/'.$s->doi : ''), 'title' => $s->title, 'content' => mb_substr((string) $s->text, 0, 12000)])->filter(fn ($r) => $r['url'])->values()->all();
        } elseif ($data['operation'] === 'read') {
            $url = WebSearch::canonicalUrl($data['query']);
            abort_unless($url, 422, 'Invalid source URL.');
            $source = Source::where('project_id', $project)->where('url', $url)->firstOrFail();
            $text = mb_substr($reader->read($url), 0, 12000);
            $ctx->fenced(fn () => $source->update(['text' => $text, 'content_hash' => hash('sha256', $text)]));
            $rows = [['id' => $source->id, 'url' => $url, 'title' => $source->title, 'content' => $text]];
        } else {
            foreach (array_slice($search->search(mb_substr($data['query'], 0, 500)), 0, 5) as $row) {
                $url = WebSearch::canonicalUrl($row['url']);
                if (! $url) {
                    continue;
                }$source = $ctx->fenced(function () use ($project, $row, $url) {
                    $s = Source::where('project_id', $project)->where('url', $url)->first();
                    if ($s) {
                        return $s;
                    }if (Source::where('project_id', $project)->count() >= config('research.source_limit')) {
                        return null;
                    }

                    return Source::create(['project_id' => $project, 'fingerprint' => hash('sha256', $url), 'title' => $row['title'], 'url' => $url, 'authors' => [], 'metadata' => ['provider' => 'nvidia-blueprint-web', 'content_scope' => 'search_snippet'], 'text' => $row['snippet'] ?? '', 'verification' => 'unverified']);
                });
                if ($source) {
                    $rows[] = ['id' => $source->id, 'url' => $url, 'title' => $source->title, 'content' => $row['snippet'] ?? ''];
                }
            }
        }
        $ctx->event('browsing.completed', ['provider' => 'nvidia_blueprint_'.$data['operation'], 'source_count' => count($rows)]);

        return response()->json(['sources' => $rows])->header('Cache-Control', 'private, no-store');
    }
}
