<?php

namespace App\Services\Studio;

class Router
{
    public function resolve(array $input): array
    {
        $mode = $input['mode'] ?? 'auto';
        $engine = $input['engine'] ?? 'auto';
        $prompt = mb_strtolower($input['prompt'] ?? '');
        $hasImage = ($input['has_image'] ?? false) === true;
        $catalog = app(Catalog::class)->all();
        $fallback = false;
        if ($engine !== 'auto') {
            $selected = collect($catalog['data'])->firstWhere('id', $engine);
            abort_unless($selected, 422, 'Unknown engine.');
            abort_if(($mode === 'architecture' && $engine !== 'architecture') || ($mode === 'object' && $engine === 'architecture'), 422, 'The selected engine conflicts with the manual mode.');
        } else {
            $architecture = $mode === 'architecture' || ($mode === 'auto' && preg_match('/architecture|microservice|system diagram|data flow|স্থাপত্য|আর্কিটেকচার|архитектур|схем[ау]|→|->/u', $prompt));
            $mesh = ! $architecture && ($hasImage || preg_match('/realistic|image.to.3d|image.to.mesh|photoreal|বাস্তবসম্মত|реалистич/u', $prompt));
            $selected = $mesh ? collect($catalog['data'])->first(fn ($e) => $e['kind'] === 'model' && $e['available']) : null;
            if (! $selected) {
                // Without a verified GPU mesh worker (e.g. on cPanel) a mesh-style
                // prompt is still answered with a procedural scene.
                $fallback = $mesh;
                $selected = collect($catalog['data'])->firstWhere('id', $architecture ? 'architecture' : 'procedural');
            }
        }
        abort_unless($selected['available'], 503, ($selected['reason'] ?? null) === 'scene_model_required'
            ? '3D scenes need NVIDIA_API_KEY and an enabled OpenMindAI chat model on the server.'
            : 'Selected engine is unavailable: '.($selected['reason'] ?? 'capability_not_verified'));
        abort_if($selected['input'] === 'image' && ! $hasImage, 422, 'This engine requires an image. No verified text-to-image preparation workflow is configured.');

        return ['engine' => $selected['id'], 'mode' => $selected['id'] === 'architecture' ? 'architecture' : 'object', 'output' => $selected['output'], 'steps' => $selected['kind'] === 'model' ? ['image', 'generate', 'validate', 'save', 'preview'] : ['prompt', 'generate_scene', 'validate', 'save', 'preview'], 'reason' => $engine !== 'auto' ? 'manual_engine' : ($fallback ? 'procedural_fallback' : ($mode !== 'auto' ? 'manual_mode' : 'deterministic_intent'))];
    }
}
