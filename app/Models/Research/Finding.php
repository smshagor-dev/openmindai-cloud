<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Finding extends Model
{
    protected $table = 'research_findings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
