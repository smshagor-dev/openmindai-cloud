<?php

return [
    'worker_url' => env('STUDIO_WORKER_URL', ''),
    'worker_key' => env('STUDIO_WORKER_KEY', ''),
    'worker_timeout' => 30,
    'artifact_bytes' => 3000000,
    'storage_bytes' => 100000000,
];
