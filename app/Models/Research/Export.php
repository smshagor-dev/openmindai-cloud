<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Export extends Model
{
    protected $table = 'research_exports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'actor_id' => 'integer', 'bytes' => 'integer', 'snapshot' => 'array', 'draft' => 'boolean'];
    }

    protected $hidden = ['snapshot', 'path'];
}
