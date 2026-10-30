<?php

namespace App\Http\Controllers;

use App\Services\HostedCodeAgents;
use App\Services\NvidiaModelProbe;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CodeAgentsAdminController
{
    public function index(Request $request, HostedCodeAgents $agents)
    {
        return view('admin.code-agents', [
            'agents' => $agents->codeAgents(),
            'developerKeys' => $request->user()->tokens()->where('name', 'like', 'developer:%')->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->latest()->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at']),
            'apps' => DB::table('first_party_apps')->orderByDesc('created_at')->get(),
            'apiBase' => rtrim(config('app.url'), '/').'/api/v1',
            'developerApiBase' => config('developer.public_base_url'),
            'nvidiaReady' => strlen((string) config('models.nvidia_key')) >= 12,
        ]);
    }

    public function catalog(Request $request, HostedCodeAgents $agents)
    {
        $models = $agents->all();
        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));
        $categories = $models->pluck('category')->unique()->sort()->values()->all();
        $filtered = $models->filter(fn ($model) => ($category === '' || $model->category === $category) &&
            ($q === '' || str_contains(mb_strtolower(
                $model->name.' '.$model->slug.' '.$model->provider_model),
                mb_strtolower($q))));

        return view('admin.nvidia-models', [
            'grouped' => $filtered->groupBy('category'),
            'specialistModels' => config('nvidia_specialists.models', []),
            'categories' => $categories,
            'selectedCategory' => $category,
            'search' => $q,
            'total' => $models->count(),
            'configured' => $models->where('api_ready', true)->count(),
            'enabled' => $models->where('enabled', true)->count(),
            'apiBase' => config('developer.public_base_url'),
            'nvidiaReady' => strlen((string) config('models.nvidia_key')) >= 12,
        ]);
    }

    public function model(Request $request, string $slug)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|accepted',
            'api_ready' => 'sometimes|boolean',
            'include_model' => 'sometimes|boolean',
            'api_protocol' => 'sometimes|in:chat,json',
            'api_origin' => 'sometimes|in:integrate.api.nvidia.com,ai.api.nvidia.com,health.api.nvidia.com',
            'name' => 'sometimes|required|string|min:2|max:120',
            'provider_model' => ['sometimes', 'required', 'string', 'max:190',
                'regex:/^[a-zA-Z0-9][a-zA-Z0-9._\\/-]*$/',
                Rule::unique('hosted_code_agents', 'provider_model')->ignore($slug, 'slug')],
            'api_path' => ['sometimes', 'nullable', 'string', 'max:190',
                'regex:~^/v1/[a-zA-Z0-9][a-zA-Z0-9/_.-]*$~'],
        ]);
        $model = DB::table('hosted_code_agents')->where('slug', $slug)->first();
        abort_unless($model, 404);
        abort_if(isset(config('nvidia_specialists.models', [])[$slug])
            && ! isset(config('maxine.models', [])[$slug])
            && ! isset(config('nvidia_av.routes', [])[$slug])
            && $slug !== 'nvidia-cosmos-transfer2.5-2b'
            && $slug !== 'nvidia-magpie-tts-zeroshot'
            && $slug !== 'nvidia-nemotron-voicechat'
            && $slug !== 'nvidia-streampetr'
            && (($data['api_ready'] ?? false) || (($data['api_protocol'] ?? $model->api_protocol) === 'chat')),
            422, 'This model needs a dedicated provider integration before activation.');
        if (isset(config('nvidia_av.routes', [])[$slug])) {
            abort_if((isset($data['provider_model']) && $data['provider_model'] !== $model->provider_model)
                || (isset($data['api_path']) && $data['api_path'] !== '')
                || (isset($data['api_protocol']) && $data['api_protocol'] !== 'json'),
                422, 'This model uses a fixed NIM worker route.');
            $data['api_protocol'] = 'json';
            $data['api_path'] = null;
            $data['api_ready'] = true;
            $data['include_model'] = false;
        }
        if ($slug === 'nvidia-nemotron-voicechat') {
            abort_if((isset($data['provider_model']) && $data['provider_model'] !== $model->provider_model)
                || (isset($data['api_path']) && $data['api_path'] !== '')
                || (isset($data['api_protocol']) && $data['api_protocol'] !== 'json'),
                422, 'VoiceChat uses the fixed secure streaming relay.');
            $data['api_ready'] = true;
            $data['api_path'] = null;
            $data['api_protocol'] = 'json';
            $data['include_model'] = false;
        }
        if ($slug === 'nvidia-magpie-tts-zeroshot') {
            abort_if((isset($data['provider_model']) && $data['provider_model'] !== $model->provider_model)
                || (isset($data['api_path']) && $data['api_path'] !== '')
                || (isset($data['api_protocol']) && $data['api_protocol'] !== 'json'),
                422, 'Magpie uses the fixed, server-side Speech NIM route.');
            $data['api_ready'] = true;
            $data['api_path'] = null;
            $data['api_protocol'] = 'json';
            $data['include_model'] = false;
        }
        if ($slug === 'nvidia-cosmos-transfer2.5-2b') {
            abort_if((isset($data['provider_model']) && $data['provider_model'] !== $model->provider_model)
                || (isset($data['api_path']) && $data['api_path'] !== '')
                || (isset($data['api_protocol']) && $data['api_protocol'] !== 'json'),
                422, 'This model uses the fixed Cosmos Transfer queue.');
            $data['api_ready'] = true;
            $data['api_path'] = null;
            $data['api_protocol'] = 'json';
            $data['include_model'] = false;
        }
        if ($slug === 'nvidia-streampetr') {
            abort_if((isset($data['provider_model']) && $data['provider_model'] !== 'nvidia/streampetr')
                || (isset($data['api_path']) && $data['api_path'] !== '/v1/av/nvidia/streampetr')
                || (isset($data['api_protocol']) && $data['api_protocol'] !== 'json'),
                422, 'StreamPETR uses the fixed hosted scene API.');
            $data['provider_model'] = 'nvidia/streampetr';
            $data['api_origin'] = 'ai.api.nvidia.com';
            $data['api_path'] = '/v1/av/nvidia/streampetr';
            $data['api_protocol'] = 'json';
            $data['api_ready'] = true;
            $data['include_model'] = false;
        }
        if (isset(config('maxine.models', [])[$slug])) {
            abort_if((isset($data['api_path']) && $data['api_path'] !== '')
                || (isset($data['provider_model']) && $data['provider_model'] !== $model->provider_model)
                || (isset($data['api_protocol']) && $data['api_protocol'] !== 'json'),
                422, 'Maxine gRPC media uses a fixed, reviewed integration.');
        }
        if (! isset(config('maxine.models', [])[$slug]) && ! isset(config('nvidia_av.routes', [])[$slug]) && $slug !== 'nvidia-streampetr'
            && $slug !== 'nvidia-cosmos-transfer2.5-2b'
            && $slug !== 'nvidia-magpie-tts-zeroshot'
            && $slug !== 'nvidia-nemotron-voicechat'
            && array_key_exists('api_ready', $data) && $data['api_ready']) {
            $path = $data['api_path'] ?? $model->api_path;
            abort_unless($path && preg_match('~^/v1/[a-zA-Z0-9][a-zA-Z0-9/_.-]*$~D', $path) && ! str_contains($path, '..'),
                422, 'Configure a verified NVIDIA /v1/ API path before enabling inference.');
            if (($data['api_protocol'] ?? $model->api_protocol) === 'chat') {
                abort_unless($path === '/v1/chat/completions', 422, 'Chat models must use the chat completions endpoint.');
            }
        }
        if (isset(config('maxine.models', [])[$slug])) {
            // Media gRPC models use the separate trusted worker, not a raw
            // Laravel JSON relay selected from admin-provided API paths.
            $data['api_ready'] = true;
            $data['api_protocol'] = 'json';
            $data['api_path'] = null;
            $data['include_model'] = false;
        }
        if ($slug === 'nvidia-cosmos3-nano') {
            // Cosmos is a dedicated queued-media integration, never a generic
            // administrator-supplied JSON relay or interchangeable model ID.
            abort_if(isset($data['provider_model']) &&
                $data['provider_model'] !== 'nvidia/cosmos3-nano', 422,
                'The queued Cosmos model identifier is fixed.');
            $data['provider_model'] = 'nvidia/cosmos3-nano';
            $data['api_origin'] = 'ai.api.nvidia.com';
            $data['api_path'] = '/v1/cosmos/nvidia/cosmos3-nano';
            $data['api_protocol'] = 'json';
            $data['api_ready'] = true;
            $data['include_model'] = false;
        }
        // Catalog entries must remain visible. A valid API key does not grant
        // upstream NVIDIA entitlements, so non-chat integrations still require
        // an explicitly reviewed protocol and path.
        unset($data['enabled']);
        if (($data['api_protocol'] ?? $model->api_protocol) === 'chat') {
            $data['api_path'] = '/v1/chat/completions';
            $data['api_ready'] = true;
        }
        DB::table('hosted_code_agents')->where('slug', $slug)->update([
            ...$data, 'enabled' => true, 'last_tested_at' => null,
            'last_test_status' => null, 'last_test_http_status' => null, 'updated_at' => now(),
        ]);

        return back()->with('status', 'NVIDIA model configuration saved.');
    }

    public function testModel(Request $request, string $slug, TwoFactor $two)
    {
        $this->verify($request, $two);
        $model = DB::table('hosted_code_agents')->where('slug', $slug)->first();
        abort_unless($model, 404);
        abort_unless($model->api_ready, 422, 'Configure the NVIDIA API route first.');
        abort_unless(in_array($model->api_origin, [
            'integrate.api.nvidia.com', 'ai.api.nvidia.com', 'health.api.nvidia.com',
        ], true), 422, 'Unsupported NVIDIA API host.');
        $probe = app(NvidiaModelProbe::class);
        abort_unless($probe->payload($model) !== null, 422,
            'This service requires a real task-specific payload and configured worker.');
        abort_unless(strlen((string) config('models.nvidia_key')) >= 12, 503, 'Configure NVIDIA_API_KEY on your Laravel server.');
        $result = $probe->run($model);
        $status = $result['status'];
        $httpCode = $result['http_status'];
        DB::table('hosted_code_agents')->where('id', $model->id)->update([
            'last_tested_at' => now(),
            'last_test_status' => $status,
            'last_test_http_status' => $httpCode,
            'updated_at' => now(),
        ]);

        return back()->with('status', $status === 'success'
            ? $model->name.': live NVIDIA inference verified.'
            : $model->name.': NVIDIA test returned '.$status.'; check the model, access and quotas.');
    }

    private function verify(Request $request, TwoFactor $two): void
    {
        $request->validate(['current_password' => 'required|current_password', 'code' => 'nullable|string|max:64']);
        if ($request->user()->two_factor_confirmed_at) {
            abort_unless($two->verify($request->user(), (string) $request->input('code', '')), 422, 'Valid two-factor code required.');
        }
    }

    public function addApp(Request $request, TwoFactor $two)
    {
        $this->verify($request, $two);
        $data = $request->validate(['name' => 'required|string|min:3|max:120']);
        abort_if(DB::table('first_party_apps')->where('enabled', true)->count() >= 20, 422, 'Too many registered apps.');
        $appId = 'openmindai-'.bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(32));
        DB::table('first_party_apps')->insert([
            'app_id' => $appId,
            'name' => trim($data['name']),
            'owner_user_id' => $request->user()->id,
            'encrypted_secret' => Crypt::encryptString($secret),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'First-party app registered. Reveal and copy its signing secret below after verifying your administrator credentials.');
    }

    public function revealAppSecret(Request $request, int $app, TwoFactor $two)
    {
        // Viewing stored signing material requires reauthentication on every
        // request. Never send encrypted_secret to the Blade page or its data.
        $this->verify($request, $two);
        $row = DB::table('first_party_apps')->where('id', $app)->where('enabled', true)->first();
        abort_unless($row, 404, 'Active application not found.');

        return response()->json([
            'app_id' => $row->app_id,
            'signing_secret' => Crypt::decryptString($row->encrypted_secret),
        ])->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function deleteApp(Request $request, int $app, TwoFactor $two)
    {
        $this->verify($request, $two);
        abort_unless(DB::table('first_party_apps')->where('id', $app)->delete() === 1, 404);

        return back()->with('status', 'Registered application permanently deleted. Its signing credentials can no longer be used.');
    }

    public function disableApp(Request $request, int $app, TwoFactor $two)
    {
        $this->verify($request, $two);
        abort_unless(DB::table('first_party_apps')->where('id', $app)->update(['enabled' => false, 'updated_at' => now()]), 404);

        return back()->with('status', 'First-party app access revoked.');
    }
}
