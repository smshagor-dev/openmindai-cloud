<?php

return ['default' => env('MAIL_MAILER', 'log'), 'mailers' => ['smtp' => ['transport' => 'smtp', 'host' => env('MAIL_HOST'), 'port' => env('MAIL_PORT', 587), 'username' => env('MAIL_USERNAME'), 'password' => env('MAIL_PASSWORD')], 'log' => ['transport' => 'log']], 'from' => ['address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'), 'name' => 'OpenMindAI']];
