<?php
namespace App\Models\Research;
use Illuminate\Database\Eloquent\Model;
class Run extends Model {protected $table='research_runs'; protected $guarded=['id']; protected function casts(): array {return ['plan'=>'array'];}  }
