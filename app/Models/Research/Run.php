<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Run extends Model
{
    protected $table = 'research_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['actor_id' => 'integer', 'project_id' => 'integer', 'generation' => 'integer', 'reserved_tokens' => 'integer', 'plan' => 'array'];
    }
}
