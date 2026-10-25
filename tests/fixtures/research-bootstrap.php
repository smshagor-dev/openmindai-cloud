<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$user = User::create(['name' => 'Browser fixture', 'email' => 'research-browser-fixture@example.test', 'password' => 'DisposableTestPassword123']);
$user->forceFill(['email_verified_at' => now()])->save();
echo json_encode(['token' => $user->createToken('research-browser-test', ['user'])->plainTextToken]);
