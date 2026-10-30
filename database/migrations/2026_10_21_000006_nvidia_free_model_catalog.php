<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosted_code_agents', function (Blueprint $table) {
            $table->string('category', 32)->default('Coding');
            $table->string('api_protocol', 20)->default('chat');
            $table->string('api_path', 190)->nullable();
            $table->string('catalog_url')->nullable();
            $table->string('summary', 512)->nullable();
            $table->boolean('api_ready')->default(false);
            $table->boolean('include_model')->default(true);
            $table->boolean('free_endpoint')->default(true);
        });

        // The original three aliases retain their enabled state and working chat gateway.
        DB::table('hosted_code_agents')->whereIn('slug', ['openmindai-super', 'openmindai-ultra', 'openmindai-lite'])
            ->update(['category' => 'Coding', 'api_protocol' => 'chat',
                'api_path' => '/v1/chat/completions', 'api_ready' => true, 'updated_at' => now()]);

        // Source: NVIDIA Build model catalog, 2026-10-21. A catalog badge does not
        // verify the API schema or the account's model entitlement.
        $catalog = json_decode(<<<'CATALOG'
[
  {
    "slug": "nvidia-nemotron-3.5-rerank-8b",
    "name": "nemotron-3.5-rerank-8b",
    "provider_model": "nvidia/nemotron-3.5-rerank-8b",
    "category": "Search",
    "api_protocol": "json",
    "summary": "Relevance reranking of passages and document images",
    "catalog_url": "https://build.nvidia.com/nvidia/nemotron-3.5-rerank-8b",
    "original": false
  },
  {
    "slug": "nvidia-nemotron-3.5-embed-1b",
    "name": "nemotron-3.5-embed-1b",
    "provider_model": "nvidia/nemotron-3.5-embed-1b",
    "category": "Embeddings",
    "api_protocol": "json",
    "summary": "Multimodal 1B embeddings",
    "catalog_url": "https://build.nvidia.com/nvidia/nemotron-3.5-embed-1b",
    "original": false
  },
  {
    "slug": "nvidia-nemotron-3.5-embed-8b",
    "name": "nemotron-3.5-embed-8b",
    "provider_model": "nvidia/nemotron-3.5-embed-8b",
    "category": "Embeddings",
    "api_protocol": "json",
    "summary": "Multimodal 8B embeddings",
    "catalog_url": "https://build.nvidia.com/nvidia/nemotron-3.5-embed-8b",
    "original": false
  },
  {
    "slug": "nvidia-rfd4-proteina",
    "name": "rfd4-proteina",
    "provider_model": "ipd/rfd4-proteina",
    "category": "Science",
    "api_protocol": "json",
    "summary": "Protein and antibody design",
    "catalog_url": "https://build.nvidia.com/ipd/rfd4-proteina",
    "original": false
  },
  {
    "slug": "openmindai-super",
    "name": "OpenMindAI Super",
    "provider_model": "nvidia/nemotron-3.5-super-vl-120b-a12b",
    "category": "Coding",
    "api_protocol": "chat",
    "summary": "Reasoning, coding and multimodal tools",
    "catalog_url": "https://build.nvidia.com/nvidia/nemotron-3.5-super-vl-120b-a12b",
    "original": true
  },
  {
    "slug": "nvidia-step-5-preview",
    "name": "step-5-preview",
    "provider_model": "stepfun-ai/step-5-preview",
    "category": "Chat",
    "api_protocol": "chat",
    "summary": "Long-context multimodal reasoning",
    "catalog_url": "https://build.nvidia.com/stepfun-ai/step-5-preview",
    "original": false
  },
  {
    "slug": "nvidia-kumo-tabular",
    "name": "kumo-tabular",
    "provider_model": "nvidia/kumo-tabular",
    "category": "Data",
    "api_protocol": "json",
    "summary": "Tabular classification and regression",
    "catalog_url": "https://build.nvidia.com/nvidia/kumo-tabular",
    "original": false
  },
  {
    "slug": "nvidia-3d-body-pose",
    "name": "3d-body-pose",
    "provider_model": "nvidia/3d-body-pose",
    "category": "Vision",
    "api_protocol": "json",
    "summary": "3D body pose from video",
    "catalog_url": "https://build.nvidia.com/nvidia/3d-body-pose",
    "original": false
  },
  {
    "slug": "nvidia-deepseek-v4.1-flash",
    "name": "deepseek-v4.1-flash",
    "provider_model": "deepseek-ai/deepseek-v4.1-flash",
    "category": "Chat",
    "api_protocol": "chat",
    "summary": "Fast multimodal reasoning",
    "catalog_url": "https://build.nvidia.com/deepseek-ai/deepseek-v4.1-flash",
    "original": false
  },
  {
    "slug": "nvidia-kumo-relational",
    "name": "kumo-relational",
    "provider_model": "nvidia/kumo-relational",
    "category": "Data",
    "api_protocol": "json",
    "summary": "Relational structured-data predictions",
    "catalog_url": "https://build.nvidia.com/nvidia/kumo-relational",
    "original": false
  },
  {
    "slug": "nvidia-glm-5-3",
    "name": "glm-5-3",
    "provider_model": "z-ai/glm-5-3",
    "category": "Chat",
    "api_protocol": "chat",
    "summary": "Text reasoning and tool calling; deprecation announced",
    "catalog_url": "https://build.nvidia.com/z-ai/glm-5-3",
    "original": false
  },
  {
    "slug": "nvidia-glm-5-3-flash",
    "name": "glm-5-3-flash",
    "provider_model": "z-ai/glm-5-3-flash",
    "category": "Chat",
    "api_protocol": "chat",
    "summary": "Fast multimodal reasoning and tools",
    "catalog_url": "https://build.nvidia.com/z-ai/glm-5-3-flash",
    "original": false
  },
  {
    "slug": "openmindai-lite",
    "name": "OpenMindAI Lite",
    "provider_model": "nvidia/nemotron-3.5-lightning-30b-a3b",
    "category": "Coding",
    "api_protocol": "chat",
    "summary": "Fast coding and specialized tasks",
    "catalog_url": "https://build.nvidia.com/nvidia/nemotron-3.5-lightning-30b-a3b",
    "original": true
  },
  {
    "slug": "nvidia-muse-glimmer-30b",
    "name": "muse-glimmer-30b",
    "provider_model": "meta/muse-glimmer-30b",
    "category": "Vision",
    "api_protocol": "chat",
    "summary": "Multimodal reasoning and tool calling",
    "catalog_url": "https://build.nvidia.com/meta/muse-glimmer-30b",
    "original": false
  },
  {
    "slug": "nvidia-riva-translate-4b-instruct-v2",
    "name": "riva-translate-4b-instruct-v2",
    "provider_model": "nvidia/riva-translate-4b-instruct-v2",
    "category": "Audio",
    "api_protocol": "json",
    "summary": "Translation",
    "catalog_url": "https://build.nvidia.com/nvidia/riva-translate-4b-instruct-v2",
    "original": false
  },
  {
    "slug": "nvidia-ising-calibration-1.5-31b",
    "name": "ising-calibration-1.5-31b",
    "provider_model": "nvidia/ising-calibration-1.5-31b",
    "category": "Science",
    "api_protocol": "chat",
    "summary": "Quantum calibration chart understanding",
    "catalog_url": "https://build.nvidia.com/nvidia/ising-calibration-1.5-31b",
    "original": false
  },
  {
    "slug": "nvidia-nemotron-3-embed-1b",
    "name": "nemotron-3-embed-1b",
    "provider_model": "nvidia/nemotron-3-embed-1b",
    "category": "Embeddings",
    "api_protocol": "json",
    "summary": "Semantic retrieval embeddings",
    "catalog_url": "https://build.nvidia.com/nvidia/nemotron-3-embed-1b",
    "original": false
  },
  {
    "slug": "nvidia-laguna-xs-2.1",
    "name": "laguna-xs-2.1",
    "provider_model": "poolside/laguna-xs-2.1",
    "category": "Coding",
    "api_protocol": "chat",
    "summary": "Coding and terminal tasks",
    "catalog_url": "https://build.nvidia.com/poolside/laguna-xs-2.1",
    "original": false
  },
  {
    "slug": "nvidia-diffusiongemma-26b-a4b-it",
    "name": "diffusiongemma-26b-a4b-it",
    "provider_model": "google/diffusiongemma-26b-a4b-it",
    "category": "Chat",
    "api_protocol": "chat",
    "summary": "Diffusion text generation",
    "catalog_url": "https://build.nvidia.com/google/diffusiongemma-26b-a4b-it",
    "original": false
  },
  {
    "slug": "openmindai-ultra",
    "name": "OpenMindAI Ultra",
    "provider_model": "nvidia/nemotron-3-ultra-550b-a55b",
    "category": "Coding",
    "api_protocol": "chat",
    "summary": "Advanced coding, planning and tools",
    "catalog_url": "https://build.nvidia.com/nvidia/nemotron-3-ultra-550b-a55b",
    "original": true
  },
  {
    "slug": "nvidia-nemotron-3.5-content-safety",
    "name": "nemotron-3.5-content-safety",
    "provider_model": "nvidia/nemotron-3.5-content-safety",
    "category": "Safety",
    "api_protocol": "chat",
    "summary": "Multimodal content safety",
    "catalog_url": "https://build.nvidia.com/nvidia/nemotron-3.5-content-safety",
    "original": false
  },
  {
    "slug": "nvidia-cosmos3-nano",
    "name": "cosmos3-nano",
    "provider_model": "nvidia/cosmos3-nano",
    "category": "Video",
    "api_protocol": "json",
    "summary": "Video, image and action generation",
    "catalog_url": "https://build.nvidia.com/nvidia/cosmos3-nano",
    "original": false
  },
  {
    "slug": "nvidia-cosmos3-nano-reasoner",
    "name": "cosmos3-nano-reasoner",
    "provider_model": "nvidia/cosmos3-nano-reasoner",
    "category": "Vision",
    "api_protocol": "chat",
    "summary": "Video and image reasoning",
    "catalog_url": "https://build.nvidia.com/nvidia/cosmos3-nano-reasoner",
    "original": false
  },
  {
    "slug": "nvidia-nemotron-3-nano-omni-30b-a3b-reasoning",
    "name": "nemotron-3-nano-omni-30b-a3b-reasoning",
    "provider_model": "nvidia/nemotron-3-nano-omni-30b-a3b-reasoning",
    "category": "Vision",
    "api_protocol": "chat",
    "summary": "Audio, image, video and text understanding",
    "catalog_url": "https://build.nvidia.com/nvidia/nemotron-3-nano-omni-30b-a3b-reasoning",
    "original": false
  },
  {
    "slug": "nvidia-synthetic-video-detector",
    "name": "synthetic-video-detector",
    "provider_model": "nvidia/synthetic-video-detector",
    "category": "Video",
    "api_protocol": "json",
    "summary": "Synthetic video detection",
    "catalog_url": "https://build.nvidia.com/nvidia/synthetic-video-detector",
    "original": false
  },
  {
    "slug": "nvidia-active-speaker-detection",
    "name": "active-speaker-detection",
    "provider_model": "nvidia/active-speaker-detection",
    "category": "Video",
    "api_protocol": "json",
    "summary": "Speaker identity from video",
    "catalog_url": "https://build.nvidia.com/nvidia/active-speaker-detection",
    "original": false
  },
  {
    "slug": "nvidia-ising-calibration-1-35b-a3b",
    "name": "ising-calibration-1-35b-a3b",
    "provider_model": "nvidia/ising-calibration-1-35b-a3b",
    "category": "Science",
    "api_protocol": "chat",
    "summary": "Quantum calibration charts",
    "catalog_url": "https://build.nvidia.com/nvidia/ising-calibration-1-35b-a3b",
    "original": false
  },
  {
    "slug": "nvidia-gemma-4-31b-it",
    "name": "gemma-4-31b-it",
    "provider_model": "google/gemma-4-31b-it",
    "category": "Chat",
    "api_protocol": "chat",
    "summary": "Coding and agentic workflows",
    "catalog_url": "https://build.nvidia.com/google/gemma-4-31b-it",
    "original": false
  },
  {
    "slug": "nvidia-nemotron-voicechat",
    "name": "nemotron-voicechat",
    "provider_model": "nvidia/nemotron-voicechat",
    "category": "Audio",
    "api_protocol": "json",
    "summary": "Voice conversation",
    "catalog_url": "https://build.nvidia.com/nvidia/nemotron-voicechat",
    "original": false
  },
  {
    "slug": "nvidia-cosmos-transfer2.5-2b",
    "name": "cosmos-transfer2.5-2b",
    "provider_model": "nvidia/cosmos-transfer2.5-2b",
    "category": "Video",
    "api_protocol": "json",
    "summary": "Physics-aware video world generation",
    "catalog_url": "https://build.nvidia.com/nvidia/cosmos-transfer2.5-2b",
    "original": false
  },
  {
    "slug": "nvidia-streampetr",
    "name": "streampetr",
    "provider_model": "nvidia/streampetr",
    "category": "Vision",
    "api_protocol": "json",
    "summary": "Automotive 3D object detection",
    "catalog_url": "https://build.nvidia.com/nvidia/streampetr",
    "original": false
  },
  {
    "slug": "nvidia-llama-3.1-nemotron-safety-guard-8b-v3",
    "name": "llama-3.1-nemotron-safety-guard-8b-v3",
    "provider_model": "nvidia/llama-3.1-nemotron-safety-guard-8b-v3",
    "category": "Safety",
    "api_protocol": "chat",
    "summary": "Safety moderation",
    "catalog_url": "https://build.nvidia.com/nvidia/llama-3.1-nemotron-safety-guard-8b-v3",
    "original": false
  },
  {
    "slug": "nvidia-gpt-oss-20b",
    "name": "gpt-oss-20b",
    "provider_model": "openai/gpt-oss-20b",
    "category": "Chat",
    "api_protocol": "chat",
    "summary": "Text reasoning and math",
    "catalog_url": "https://build.nvidia.com/openai/gpt-oss-20b",
    "original": false
  },
  {
    "slug": "nvidia-llama-guard-4-12b",
    "name": "llama-guard-4-12b",
    "provider_model": "meta/llama-guard-4-12b",
    "category": "Safety",
    "api_protocol": "chat",
    "summary": "Multimodal safety classification",
    "catalog_url": "https://build.nvidia.com/meta/llama-guard-4-12b",
    "original": false
  },
  {
    "slug": "nvidia-background-noise-removal",
    "name": "background-noise-removal",
    "provider_model": "nvidia/background-noise-removal",
    "category": "Audio",
    "api_protocol": "json",
    "summary": "Speech noise removal",
    "catalog_url": "https://build.nvidia.com/nvidia/background-noise-removal",
    "original": false
  },
  {
    "slug": "nvidia-magpie-tts-zeroshot",
    "name": "magpie-tts-zeroshot",
    "provider_model": "nvidia/magpie-tts-zeroshot",
    "category": "Audio",
    "api_protocol": "json",
    "summary": "Voice-cloned text-to-speech",
    "catalog_url": "https://build.nvidia.com/nvidia/magpie-tts-zeroshot",
    "original": false
  },
  {
    "slug": "nvidia-nemotron-3-super-120b-a12b",
    "name": "nemotron-3-super-120b-a12b",
    "provider_model": "nvidia/nemotron-3-super-120b-a12b",
    "category": "Coding",
    "api_protocol": "chat",
    "summary": "Long-context planning and reasoning",
    "catalog_url": "https://build.nvidia.com/nvidia/nemotron-3-super-120b-a12b",
    "original": false
  },
  {
    "slug": "nvidia-sparsedrive",
    "name": "sparsedrive",
    "provider_model": "nvidia/sparsedrive",
    "category": "Vision",
    "api_protocol": "json",
    "summary": "Autonomous driving perception and planning",
    "catalog_url": "https://build.nvidia.com/nvidia/sparsedrive",
    "original": false
  },
  {
    "slug": "nvidia-bevformer",
    "name": "bevformer",
    "provider_model": "nvidia/bevformer",
    "category": "Vision",
    "api_protocol": "json",
    "summary": "Bird's-eye-view perception",
    "catalog_url": "https://build.nvidia.com/nvidia/bevformer",
    "original": false
  },
  {
    "slug": "nvidia-studio-voice",
    "name": "studio-voice",
    "provider_model": "nvidia/studio-voice",
    "category": "Audio",
    "api_protocol": "json",
    "summary": "Studio-quality speech enhancement",
    "catalog_url": "https://build.nvidia.com/nvidia/studio-voice",
    "original": false
  },
  {
    "slug": "nvidia-llama-3.2-11b-vision-instruct",
    "name": "llama-3.2-11b-vision-instruct",
    "provider_model": "meta/llama-3.2-11b-vision-instruct",
    "category": "Vision",
    "api_protocol": "chat",
    "summary": "Vision-language reasoning",
    "catalog_url": "https://build.nvidia.com/meta/llama-3.2-11b-vision-instruct",
    "original": false
  },
  {
    "slug": "nvidia-llama-3.2-90b-vision-instruct",
    "name": "llama-3.2-90b-vision-instruct",
    "provider_model": "meta/llama-3.2-90b-vision-instruct",
    "category": "Vision",
    "api_protocol": "chat",
    "summary": "Large vision-language reasoning",
    "catalog_url": "https://build.nvidia.com/meta/llama-3.2-90b-vision-instruct",
    "original": false
  }
]
CATALOG, true, 512, JSON_THROW_ON_ERROR);
        foreach ($catalog as $model) {
            $record = [
                'category' => $model['category'],
                'api_protocol' => $model['api_protocol'],
                'api_path' => $model['api_protocol'] === 'chat' ? '/v1/chat/completions' : null,
                'catalog_url' => $model['catalog_url'],
                'summary' => $model['summary'],
                'free_endpoint' => true,
                'updated_at' => now(),
            ];
            if ($model['original']) {
                DB::table('hosted_code_agents')->where('slug', $model['slug'])->update($record);

                continue;
            }
            DB::table('hosted_code_agents')->insertOrIgnore([
                ...$record,
                'slug' => $model['slug'],
                'name' => $model['name'],
                'provider_model' => $model['provider_model'],
                'enabled' => false,
                'api_ready' => false,
                'include_model' => $model['api_protocol'] === 'chat',
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Catalog rows are removed before the added schema columns are dropped.
        DB::table('hosted_code_agents')->whereNotIn('slug',
            ['openmindai-super', 'openmindai-ultra', 'openmindai-lite'])->delete();
        Schema::table('hosted_code_agents', function (Blueprint $table) {
            $table->dropColumn(['category', 'api_protocol', 'api_path',
                'catalog_url', 'summary', 'api_ready', 'include_model', 'free_endpoint']);
        });
    }
};
