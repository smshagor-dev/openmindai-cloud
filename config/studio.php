<?php

return [
    'scene_execution' => env('STUDIO_SCENE_EXECUTION', 'direct'),
    'queue_enabled' => (bool) env('STUDIO_QUEUE_ENABLED', false),
    'worker_url' => env('STUDIO_WORKER_URL', ''),
    'worker_key' => env('STUDIO_WORKER_KEY', ''),
    'worker_timeout' => 30,
    'artifact_bytes' => 3000000,
    'storage_bytes' => 100000000,
];
