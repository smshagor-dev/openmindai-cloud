<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Evidence extends Model
{
    protected $table = 'research_evidence';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }
}
