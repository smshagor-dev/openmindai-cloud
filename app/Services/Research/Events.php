<?php
namespace App\Services\Research;
use Illuminate\Support\Facades\DB;
class Events {
 public static function add(int $project,string $type,array $data=[],?int $task=null): void {
 $safe=array_intersect_key($data,array_flip(['agent','status','query','provider','title','url','domain','source_id','count','total','completed','stage','error_code','model','usage','version','execution_id','format','gate','role','task_id']));
 foreach($safe as $k=>$v)if(is_string($v))$safe[$k]=mb_substr($v,0,1500);
 DB::table('research_events')->insert(['project_id'=>$project,'task_id'=>$task,'type'=>$type,'data'=>json_encode($safe,JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now()]);
 }
}
