<?php
namespace App\Models\Research;
use Illuminate\Database\Eloquent\Model;
class Project extends Model {protected $table='research_projects'; protected $guarded=['id']; protected function casts(): array {return ['settings'=>'array'];}  }
