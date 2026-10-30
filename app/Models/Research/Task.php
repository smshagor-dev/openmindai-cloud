<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $table = 'research_tasks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'run_id' => 'integer', 'definition_id' => 'integer', 'attempts' => 'integer', 'errors' => 'integer', 'input' => 'array', 'output' => 'array', 'checkpoint' => 'array', 'lease_until' => 'datetime', 'available_at' => 'datetime'];
    }
}
