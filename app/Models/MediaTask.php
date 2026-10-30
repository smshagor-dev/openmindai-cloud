<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MediaTask extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['input_path', 'output_path', 'cloud_mask_path'];

    protected function casts(): array
    {
        return ['artifacts' => 'array', 'cloud_options' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $task) {
            Storage::disk('local')->delete(array_filter([$task->input_path, $task->output_path, $task->cloud_mask_path]));
        });
    }
}
