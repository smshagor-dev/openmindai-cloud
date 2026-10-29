<?php

namespace App\Http\Controllers;

use App\Services\ApiCreditUsage;
use App\Services\HostedCodeAgents;
use App\Services\StudioScene;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SceneStudioController
{
    private function owned(Request $request, string $id)
    {
        return DB::table('studio_scenes')->where('user_id', $request->user()->id)->where('id', $id);
    }

    public function index(Request $request)
    {
        return response()->json(['data' => DB::table('studio_scenes')->where('user_id', $request->user()->id)->orderByDesc('id')->limit(100)->get(['id', 'title', 'mode', 'created_at'])])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $id)
    {
        $row = $this->owned($request, $id)->first();
        abort_unless($row, 404);
        $row->scene = json_decode($row->scene, true);

        return response()->json($row)->header('Cache-Control', 'no-store');
    }

    public function delete(Request $request, string $id)
    {
        abort_unless($this->owned($request, $id)->delete(), 404);

        return response()->noContent();
    }

    public function create(Request $request, StudioScene $scenes, OpenMindAiGatewayController $gateway, HostedCodeAgents $agents, ApiCreditUsage $credits)
    {
        $data = $request->validate(['prompt' => 'required|string|min:5|max:8000', 'mode' => 'required|in:object,architecture']);
        $lock = Cache::lock('3d-studio:'.$request->user()->id, 300);
        abort_unless($lock->get(), 409, 'A 3D generation is already running.');
        try {
            $input = Request::create('/api/v1/mobile/chat/completions', 'POST', [
                'model' => 'openmindai-ultra', 'stream' => false, 'max_tokens' => 8192, 'temperature' => 0.2,
                'messages' => [['role' => 'system', 'content' => StudioScene::CONTRACT], ['role' => 'user', 'content' => json_encode($data, JSON_UNESCAPED_UNICODE)]],
            ]);
            $input->attributes->set('studio_scene_json', true);
            $input->setUserResolver(fn () => $request->user());
            $response = $gateway->mobileChat($input, $agents, $credits);
            if ($response->getStatusCode() !== 200) {
                return $response;
            }
            $content = $response->getData(true)['choices'][0]['message']['content'] ?? null;
            abort_unless(is_string($content) && strlen($content) <= 150000, 502, 'The model did not return a valid scene.');
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($content));
            $scene = json_decode($content, true, 32);
            abort_unless(is_array($scene), 502, 'The model returned invalid scene JSON. Try a more specific prompt.');
            try {
                $scene = $scenes->validate($scene);
            } catch (ValidationException $e) {
                abort(502, 'The generated scene failed validation. Try a simpler or more specific prompt.');
            }
            $id = DB::table('studio_scenes')->insertGetId(['user_id' => $request->user()->id, 'title' => $scene['title'], 'mode' => $data['mode'], 'prompt' => $data['prompt'], 'scene' => json_encode($scene, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);

            return response()->json(['id' => $id, 'mode' => $data['mode'], 'prompt' => $data['prompt'], 'scene' => $scene], 201)->header('Cache-Control', 'no-store');
        } finally {
            $lock->release();
        }
    }
}
