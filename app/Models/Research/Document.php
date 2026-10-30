<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = 'research_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'version' => 'integer', 'content' => 'array'];
    }
}
