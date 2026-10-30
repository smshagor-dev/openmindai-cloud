<?php

return [
    // A private, approved NVIDIA Speech NIM deployment, never the NVIDIA
    // Build web demo or its unavailable public "apply for access" page.
    'url' => env('MAGPIE_TTS_NIM_URL'),
    'token' => env('MAGPIE_TTS_NIM_TOKEN'),
    'max_output_bytes' => 12582912,
    'retention_hours' => 24,
    'timeout' => 600,
    'languages' => ['en-US', 'es-US', 'fr-FR', 'de-DE', 'zh-CN', 'vi-VN', 'it-IT', 'hi-IN', 'ja-JP', 'ar-XA', 'pt-BR', 'ko-KR'],
    'voices' => [
        'Magpie-ZeroShot-Multilingual.Female',
        'Magpie-ZeroShot-Multilingual.Male',
        'Magpie-ZeroShot-Multilingual',
    ],
];
