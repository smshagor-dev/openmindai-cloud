<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $table = 'research_projects';

    protected $guarded = ['id'];

    public function getStatusAttribute(string $status): string
    {
        return $status === 'awaiting_approval' ? 'completed' : $status;
    }

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'generation' => 'integer', 'settings' => 'array'];
    }
}
