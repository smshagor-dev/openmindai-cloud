<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Run extends Model
{
    protected $table = 'research_runs';

    protected $guarded = ['id'];

    protected $hidden = ['emailed_generation'];

    protected function casts(): array
    {
        return ['emailed_generation' => 'integer', 'actor_id' => 'integer', 'project_id' => 'integer', 'generation' => 'integer', 'reserved_tokens' => 'integer', 'plan' => 'array'];
    }
}
