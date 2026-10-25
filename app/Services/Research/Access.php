<?php
namespace App\Services\Research;
use App\Models\Research\Project;
use Illuminate\Support\Facades\DB;
class Access {
 public function project(int $id,int $user,array $roles=['owner','editor','reviewer','viewer']): Project {
 $p=Project::findOrFail($id); $role=$p->owner_id===$user?'owner':DB::table('research_members')->where('project_id',$id)->where('user_id',$user)->whereNotNull('accepted_at')->value('role');
 abort_unless(in_array($role,$roles,true),404); $p->setAttribute('role',$role); return $p;
 }
}
