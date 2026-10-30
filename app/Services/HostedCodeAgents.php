<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class HostedCodeAgents
{
    public function enabled()
    {
        return DB::table('hosted_code_agents')->where('enabled', true)->where('api_ready', true)->where('api_protocol', 'chat')
            ->where(fn ($query) => $query->whereNull('last_test_http_status')->orWhere('last_test_http_status', '!=', 410))
            ->orderByRaw("CASE slug WHEN 'openmindai-super' THEN 1 WHEN 'openmindai-ultra' THEN 2 WHEN 'openmindai-lite' THEN 3 ELSE 4 END")
            ->orderBy('name')->get();
    }

    public function all()
    {
        return DB::table('hosted_code_agents')->orderBy('category')->orderBy('name')->get();
    }

    public function codeAgents()
    {
        return DB::table('hosted_code_agents')->whereIn('slug',
            ['openmindai-super', 'openmindai-ultra', 'openmindai-lite'])
            ->orderBy('id')->get();
    }

    public function find(string $slug)
    {
        return DB::table('hosted_code_agents')->where('slug', $slug)
            ->where('enabled', true)->where('api_ready', true)
            ->where(fn ($query) => $query->whereNull('last_test_http_status')->orWhere('last_test_http_status', '!=', 410))->first();
    }

    public function publicModels(): array
    {
        return $this->all()->map(fn ($model) => [
            'id' => $model->slug,
            'object' => 'model',
            'created' => $model->created_at ? strtotime($model->created_at) : 0,
            'owned_by' => 'openmindai',
            'name' => $model->name,
            'type' => $model->category,
            'supports_vision' => MobileVisionPayload::supports($model),
            'active' => (bool) $model->enabled,
            'available' => (bool) ($model->enabled && $model->api_ready && (int) $model->last_test_http_status !== 410 && app(HostedModelRuntime::class)->configured($model->slug)),
            'inference_status' => ! $model->enabled ? 'disabled' : ((int) $model->last_test_http_status === 410 ? 'retired' : ($model->api_ready && app(HostedModelRuntime::class)->configured($model->slug) ? 'configured' : 'setup_required')),
            'replacement_model' => $model->slug === 'nvidia-ising-calibration-1-35b-a3b' ? 'nvidia-ising-calibration-1.5-31b' : null,
            'provider_verified' => $model->last_test_status === 'success',
            'runtime' => app(HostedModelRuntime::class)->inspect($model->slug),
            'integration_requirements' => config('nvidia_specialists.models.'.$model->slug),
            'operation' => $model->slug === 'nvidia-nemotron-voicechat'
                ? 'realtime.audio'
                : ($model->slug === 'nvidia-magpie-tts-zeroshot'
                ? 'audio.synthesize'
                : ($model->slug === 'nvidia-cosmos-transfer2.5-2b'
                ? 'video.transfer'
                : (isset(config('nvidia_av.routes', [])[$model->slug])
                ? 'scene.process'
                : ($model->slug === 'nvidia-streampetr'
                ? 'scene.process'
                : (isset(config('maxine.models', [])[$model->slug])
                ? 'media.process'
                : ($model->slug === 'nvidia-cosmos3-nano' ? 'generate' : ($model->api_protocol === 'chat' ? 'chat.completions' : 'invoke'))))))),
        ])->all();
    }

    public function publicCatalog(): array
    {
        // Catalog visibility is permanent and independent of the provider's
        // availability, account entitlement, and per-model API configuration.
        return $this->all()
            ->map(fn ($model) => [
                'id' => $model->slug,
                'object' => 'model',
                'name' => $model->name,
                'category' => $model->category,
                'description' => $model->summary,
                'free_endpoint' => (bool) $model->free_endpoint,
                'listed' => true,
                'provider_access' => $model->last_test_status === 'success' ? 'verified' : 'unverified',
                'active' => (bool) $model->enabled,
                'available' => (bool) ($model->enabled && $model->api_ready && (int) $model->last_test_http_status !== 410 && app(HostedModelRuntime::class)->configured($model->slug)),
                'inference_status' => ! $model->enabled ? 'disabled' : ((int) $model->last_test_http_status === 410 ? 'retired' : ($model->api_ready && app(HostedModelRuntime::class)->configured($model->slug) ? 'configured' : 'setup_required')),
                'replacement_model' => $model->slug === 'nvidia-ising-calibration-1-35b-a3b' ? 'nvidia-ising-calibration-1.5-31b' : null,
                'provider_verified' => $model->last_test_status === 'success',
                'runtime' => app(HostedModelRuntime::class)->inspect($model->slug),
                'integration_requirements' => config('nvidia_specialists.models.'.$model->slug),
                'last_test_status' => $model->last_test_status,
                'last_tested_at' => $model->last_tested_at,
                'operation' => $model->slug === 'nvidia-nemotron-voicechat'
                ? 'realtime.audio'
                : ($model->slug === 'nvidia-magpie-tts-zeroshot'
                ? 'audio.synthesize'
                : ($model->slug === 'nvidia-cosmos-transfer2.5-2b'
                ? 'video.transfer'
                : (isset(config('nvidia_av.routes', [])[$model->slug])
                ? 'scene.process'
                : ($model->slug === 'nvidia-streampetr'
                ? 'scene.process'
                : (isset(config('maxine.models', [])[$model->slug])
                ? 'media.process'
                : ($model->slug === 'nvidia-cosmos3-nano' ? 'generate' : ($model->api_protocol === 'chat' ? 'chat.completions' : 'invoke'))))))),
                'path' => ($model->enabled && $model->api_ready && (int) $model->last_test_http_status !== 410 && app(HostedModelRuntime::class)->configured($model->slug))
                    ? ($model->slug === 'nvidia-nemotron-voicechat'
                        ? '/voicechat/sessions'
                        : ($model->slug === 'nvidia-magpie-tts-zeroshot'
                        ? '/tts/generations'
                        : ($model->slug === 'nvidia-cosmos-transfer2.5-2b'
                        ? '/transfer/generations'
                        : (isset(config('nvidia_av.routes', [])[$model->slug])
                        ? '/av-nim/'.$model->slug.'/jobs'
                        : ($model->slug === 'nvidia-streampetr'
                        ? '/av/streampetr/jobs'
                        : (isset(config('maxine.models', [])[$model->slug])
                        ? '/maxine/'.$model->slug.'/jobs'
                        : ($model->slug === 'nvidia-cosmos3-nano' ? '/cosmos/generations' : ($model->api_protocol === 'chat' ? '/chat/completions' : '/models/'.$model->slug.'/invoke'))))))))
                    : null,
            ])->all();
    }

    /** Developer API projection: coding agents only, without provider identities. */
    public function publicDeveloperCatalog(): array
    {
        return $this->codeAgents()->map(fn ($model) => [
            'id' => $model->slug,
            'object' => 'model',
            'name' => 'OpenMindAI',
            'category' => 'Coding agent',
            'description' => 'OpenMindAI coding assistance for development, debugging, and tool-based workflows.',
            'listed' => true,
            'active' => (bool) $model->enabled,
            'available' => (bool) ($model->enabled && $model->api_ready),
            'operation' => 'chat.completions',
            'path' => ($model->enabled && $model->api_ready) ? '/chat/completions' : null,
        ])->values()->all();
    }

    public function publicDeveloperModels(): array
    {
        return $this->codeAgents()->map(fn ($model) => [
            'id' => $model->slug,
            'object' => 'model',
            'name' => 'OpenMindAI',
            'created' => $model->created_at ? strtotime($model->created_at) : 0,
            'owned_by' => 'openmindai',
            'type' => 'code',
            'active' => (bool) $model->enabled,
            'available' => (bool) ($model->enabled && $model->api_ready),
            'operation' => 'chat.completions',
        ])->values()->all();
    }
}
