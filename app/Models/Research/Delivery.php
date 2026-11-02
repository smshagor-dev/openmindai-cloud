<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    protected $table = 'research_deliveries';

    protected $guarded = ['id'];

    protected $hidden = ['push_receipts'];

    protected function casts(): array
    {
        return ['draft' => 'boolean', 'notified_at' => 'datetime', 'push_receipts' => 'array'];
    }
}
