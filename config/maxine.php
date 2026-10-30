<?php

return [
    'worker_url' => env('NVIDIA_MAXINE_WORKER_URL'),
    'worker_key' => env('NVIDIA_MAXINE_WORKER_KEY'),
    'timeout' => 840,
    'max_output_bytes' => 25165824,
    'retention_hours' => 24,
    // The API will only advertise these dedicated transports, never the
    // upstream NVIDIA gRPC hostname as a pretend OpenAI JSON endpoint.
    'models' => [
        'nvidia-background-noise-removal' => [
            'mime' => 'audio/wav', 'extension' => 'wav',
            'max_bytes' => 32000000, 'output_mime' => 'audio/wav',
            'output_extension' => 'wav',
        ],
        'nvidia-studio-voice' => [
            'mime' => 'audio/wav', 'extension' => 'wav',
            'max_bytes' => 32000000, 'output_mime' => 'audio/wav',
            'output_extension' => 'wav',
        ],
        'nvidia-active-speaker-detection' => [
            'mime' => 'video/mp4', 'extension' => 'mp4',
            'max_bytes' => 20000000, 'output_mime' => 'video/mp4',
            'output_extension' => 'mp4',
        ],
        'nvidia-synthetic-video-detector' => [
            'mime' => 'video/mp4', 'extension' => 'mp4',
            'max_bytes' => 20000000, 'output_mime' => 'text/csv',
            'output_extension' => 'csv',
        ],
        'nvidia-3d-body-pose' => [
            'mime' => 'video/mp4', 'extension' => 'mp4',
            'max_bytes' => 20000000, 'output_mime' => 'application/x-ndjson',
            'output_extension' => 'json',
        ],
    ],
];
