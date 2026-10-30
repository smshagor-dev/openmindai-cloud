<?php

$publicUrl = env('DEVELOPER_API_BASE_URL');

return [
    // Developer clients connect to Next.js; Laravel is an internal service.
    // Preserve compatibility until the public portal URL is configured.
    'public_base_url' => rtrim((string) ($publicUrl ?: rtrim((string) env('APP_URL', ''), '/').'/api/v1/openmindai'), '/'),
];
