<?php

return [
    'brave_key' => env('BRAVE_SEARCH_API_KEY'),
    'google_key' => env('GOOGLE_SEARCH_API_KEY'),
    'google_cx' => env('GOOGLE_SEARCH_ENGINE_ID'),
    'attempts' => 2,
    'cooldown_seconds' => 30,
    'python_binary' => env('WEB_RESEARCH_PYTHON_BINARY', 'python3'),
];
