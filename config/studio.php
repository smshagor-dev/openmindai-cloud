<?php

return [
    'free_mesh' => [
        'enabled' => (bool) env('STUDIO_FREE_3D_ENABLED', true),
        'token' => env('STUDIO_FREE_3D_HF_TOKEN', ''),
        'user_daily' => (int) env('STUDIO_FREE_3D_USER_DAILY', 2),
        'server_daily' => (int) env('STUDIO_FREE_3D_SERVER_DAILY', 2),
    ],
    'scene_execution' => env('STUDIO_SCENE_EXECUTION', 'direct'),
    'queue_enabled' => (bool) env('STUDIO_QUEUE_ENABLED', false),
    'worker_url' => env('STUDIO_WORKER_URL', ''),
    'worker_key' => env('STUDIO_WORKER_KEY', ''),
    'worker_timeout' => 30,
    'artifact_bytes' => 3000000,
    'storage_bytes' => 100000000,
    // Image to 3D on NVIDIA's hosted TRELLIS (no local GPU). Uses the existing
    // NVIDIA key unless a dedicated one is set.
    'hosted_mesh' => [
        'key' => env('FAL_KEY', ''),
    ],
    'image_model' => [
        // Enable only for an operator-verified custom-image-capable endpoint.
        'custom_images' => (bool) env('STUDIO_IMAGE_TO_3D_CUSTOM_IMAGES', false),
        'url' => env('STUDIO_IMAGE_TO_3D_URL', 'https://ai.api.nvidia.com/v1/genai/microsoft/trellis'),
        'key' => env('STUDIO_IMAGE_TO_3D_KEY', env('NVIDIA_API_KEY', '')),
        'timeout' => (int) env('STUDIO_IMAGE_TO_3D_TIMEOUT', 170),
        'max_bytes' => 25000000,
    ],
];
