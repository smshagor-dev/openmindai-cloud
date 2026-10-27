<?php

return [
    'model' => env('RESEARCH_MODEL', 'nvidia-nemotron-3-ultra-550b-a55b'),
    'fallback_models' => array_filter(explode(',', env('RESEARCH_FALLBACK_MODELS', 'openmindai-ultra'))),
    'embedding_model' => env('RESEARCH_EMBEDDING_MODEL', ''),
    'worker_url' => env('RESEARCH_WORKER_URL', ''), 'worker_key' => env('RESEARCH_WORKER_KEY', ''),
    'worker_transport' => env('RESEARCH_WORKER_TRANSPORT', 'http'),
    'worker_snapshot' => env('RESEARCH_WORKER_SNAPSHOT_ID', ''),
    'worker_runtime_digest' => env('RESEARCH_WORKER_RUNTIME_DIGEST', ''),
    'contact' => env('RESEARCH_CONTACT_EMAIL', ''), 'openalex_key' => env('OPENALEX_API_KEY', ''),
    'concurrency' => 3, 'attempts' => 3, 'max_tasks' => 64, 'lease_seconds' => 480,
    'token_budget' => (int) env('RESEARCH_TOKEN_BUDGET', 500000), 'source_limit' => 60, 'storage_bytes' => 104857600,
];
