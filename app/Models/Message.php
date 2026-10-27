<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['inference_checkpoint', 'inference_token'];

    protected $appends = ['resumable'];

    public function getResumableAttribute(): bool
    {
        return ! empty($this->getRawOriginal('inference_checkpoint')) && (empty($this->inference_checkpoint['conversation_tools']) || ! empty($this->inference_checkpoint['deep_research'])) && empty($this->inference_checkpoint['progress']['toolRunStarted']) && $this->status !== 'complete';
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    protected function casts(): array
    {
        return ['attachment_ids' => 'array', 'sources' => 'array', 'research_metadata' => 'array', 'inference_checkpoint' => 'encrypted:array'];
    }
}
