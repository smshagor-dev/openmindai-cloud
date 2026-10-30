<?php

return [
    // Official NIM self-hosted paths. NVIDIA public API examples for these
    // models use example.com, not a verified managed endpoint.
    'worker_url' => env('NVIDIA_AV_NIM_URL'),
    'worker_token' => env('NVIDIA_AV_NIM_TOKEN'),
    'routes' => [
        'nvidia-sparsedrive' => '/v1/sparsedrive/inference',
        'nvidia-bevformer' => '/v1/bevformer/process',
    ],
    'timeout' => 720,
    'max_response_bytes' => 50331648,
];
