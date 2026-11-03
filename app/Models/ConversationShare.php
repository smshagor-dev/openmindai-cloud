<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationShare extends Model
{
    protected $guarded = ['id'];

    protected $table = 'conversation_shares';

    protected $hidden = ['token_hash'];

    public static function publish(int $userId, array $snapshot, array $source): array
    {
        abort_if(strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > 3000000, 422, 'Conversation is too large to share.');
        $token = bin2hex(random_bytes(32));
        $share = self::create($source + ['user_id' => $userId, 'token_hash' => hash('sha256', $token), 'snapshot' => $snapshot, 'expires_at' => now()->addDays(30)]);

        return ['id' => $share->id, 'token' => $token, 'expires_at' => $share->expires_at];
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'expires_at' => 'datetime'];
    }
}
