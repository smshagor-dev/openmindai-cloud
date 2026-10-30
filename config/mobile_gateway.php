<?php

return [
    // Reuse the existing shared Next.js/Laravel server secret. Never send
    // this value to browsers, mobile clients, or public API consumers.
    'bridge_key' => env('FRONTEND_CHAT_BRIDGE_KEY', ''),
];
