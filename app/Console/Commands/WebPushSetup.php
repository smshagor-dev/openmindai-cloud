<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class WebPushSetup extends Command
{
    protected $signature = 'webpush:setup';

    protected $description = 'Initialize private VAPID keys without printing credentials';

    public function handle(): int
    {
        $path = base_path('.env');
        if (! is_file($path)) {
            $this->error('Environment file is missing.');

            return self::FAILURE;
        }
        $text = file_get_contents($path);
        preg_match('/^WEB_PUSH_PUBLIC_KEY=(.*)$/m', $text, $public);
        preg_match('/^WEB_PUSH_PRIVATE_KEY=(.*)$/m', $text, $private);
        if (! empty($public[1]) && ! empty($private[1])) {
            $this->info('Web push keys already exist; preserved.');

            return self::SUCCESS;
        }
        if (! empty($public[1]) || ! empty($private[1])) {
            $this->error('Incomplete key pair; existing keys were not changed.');

            return self::FAILURE;
        }
        $keys = VAPID::createVapidKeys();
        foreach (['WEB_PUSH_PUBLIC_KEY' => $keys['publicKey'], 'WEB_PUSH_PRIVATE_KEY' => $keys['privateKey']] as $key => $value) {
            $line = $key.'='.$value;
            $text = preg_match('/^'.$key.'=.*$/m', $text) ? preg_replace('/^'.$key.'=.*$/m', $line, $text) : rtrim($text).PHP_EOL.$line.PHP_EOL;
        }
        $tmp = $path.'.webpush-'.bin2hex(random_bytes(8));
        file_put_contents($tmp, $text, LOCK_EX);
        chmod($tmp, fileperms($path) & 0777);
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            $this->error('Could not save keys.');

            return self::FAILURE;
        }$this->info('Private web push keys saved. Rebuild configuration cache.');

        return self::SUCCESS;
    }
}
