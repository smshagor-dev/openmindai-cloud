<?php

return [
    'brave_key' => env('BRAVE_SEARCH_API_KEY'),
    'google_key' => env('GOOGLE_SEARCH_API_KEY'),
    'google_cx' => env('GOOGLE_SEARCH_ENGINE_ID'),
    // Yandex Cloud Search API (API key + folder ID).
    'yandex_key' => env('YANDEX_SEARCH_API_KEY'),
    'yandex_folder' => env('YANDEX_SEARCH_FOLDER_ID'),
    'attempts' => 2,
    'cooldown_seconds' => 30,
    'reader_engine' => env('WEB_RESEARCH_READER_ENGINE', 'chromium'),
    'chromium_binary' => env('WEB_RESEARCH_CHROMIUM_BINARY'),
    'node_binary' => env('WEB_RESEARCH_NODE_BINARY', 'node'),
    'python_binary' => env('WEB_RESEARCH_PYTHON_BINARY', 'python3'),
];
