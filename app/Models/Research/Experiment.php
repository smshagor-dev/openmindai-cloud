<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Experiment extends Model
{
    protected $table = 'research_experiments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['dataset_ids' => 'array', 'result' => 'array'];
    }
}
