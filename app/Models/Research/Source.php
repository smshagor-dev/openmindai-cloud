<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
    protected $table = 'research_sources';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['authors' => 'array', 'metadata' => 'array', 'verified_at' => 'datetime'];
    }

    protected $hidden = ['private_path'];

    protected static function booted(): void
    {
        static::saving(function ($s) {
            if ($s->isDirty('text')) {
                $s->content_hash = hash('sha256', (string) $s->text);
            }
        });
    }
}
