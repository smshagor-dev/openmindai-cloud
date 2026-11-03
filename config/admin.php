<?php

return [
    'ckeditor_license_key' => env('CKEDITOR_LICENSE_KEY'),
    'seed' => [
        'name' => env('ADMIN_NAME', 'Openmindai Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'avatar_path' => env('ADMIN_AVATAR_PATH'),
    ],
];
