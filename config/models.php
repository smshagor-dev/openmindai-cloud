<?php

return [
    'nvidia_requests_per_minute' => (int) env('NVIDIA_REQUESTS_PER_MINUTE', 40), 'frontend_chat_key' => env('FRONTEND_CHAT_BRIDGE_KEY'), 'nvidia_key' => env('NVIDIA_API_KEY'), 'search_url' => env('SEARXNG_SEARCH_URL'), 'public_search_fallback' => (bool) env('PUBLIC_SEARCH_FALLBACK', true), 'inference_url' => env('INFERENCE_URL', 'http://127.0.0.1:8080/v1'), 'inference_key' => env('INFERENCE_KEY'), 'hf_token' => env('HF_TOKEN'), 'max_bytes' => (int) env('MODEL_MAX_BYTES', 10000000000), 'storage_budget' => (int) env('MODEL_STORAGE_BUDGET_BYTES', 50000000000), 'task_daily_limit' => (int) env('TASK_DAILY_LIMIT', 20), 'daily_limit' => (int) env('CHAT_DAILY_LIMIT', 200)];
