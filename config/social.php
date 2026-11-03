<?php

return [
    'google' => [
        'id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
    ],
    'facebook' => [
        'id' => env('FACEBOOK_OAUTH_APP_ID'),
        'secret' => env('FACEBOOK_OAUTH_APP_SECRET'),
        'version' => env('FACEBOOK_GRAPH_VERSION', 'v24.0'),
    ],
];
