<?php

return [
    // A separate, approved, GPU-backed realtime WebSocket relay is required.
    // Never put the worker credential inside the desktop distributed binary.
    'ws_url' => env('VOICECHAT_PUBLIC_WSS_URL'),
    'worker_key' => env('VOICECHAT_WORKER_KEY'),
    'ticket_seconds' => 60,
    'max_session_seconds' => 900,
    'max_parallel_sessions_per_user' => 1,
];
