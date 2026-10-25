<?php
namespace App\Services\Research;
use App\Models\Research\{Project,Run,Task,Finding};
use App\Jobs\Research\ExecuteTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
class Orchestrator {
 public function task(Run $run,string $key,string $agent,array $input=[],array $dependencies=[]): Task {
 abort_if(Task::where('run_id',$run->id)->count()>=config('research.max_tasks'),422,'Task graph limit.');$registry=app(Registry::class);Validator::make($input,$registry->get($agent)->definition()['input_schema'])->validate();
 $t=Task::firstOrCreate(['run_id'=>$run->id,'key'=>$key],['project_id'=>$run->project_id,'agent'=>$agent,'definition_id'=>$registry->snapshot($agent),'input'=>$input]);foreach($dependencies as $id)DB::table('research_dependencies')->insertOrIgnore(['task_id'=>$t->id,'depends_on'=>$id,'created_at'=>now(),'updated_at'=>now()]);return $t;
 }
 public function start(Project $project,int $actor,?string $component=null,array $input=[]): Run {
 return Transactions::run(function()use($project,$actor,$component,$input){$p=Project::whereKey($project->id)->lockForUpdate()->firstOrFail();abort_if(Run::where('project_id',$p->id)->whereIn('status',['running','paused'])->exists(),409,'A research run is active.');if(!$component)$p->increment('generation');$run=Run::create(['project_id'=>$p->id,'actor_id'=>$actor,'generation'=>$p->generation,'kind'=>$component?'component':'workflow','status'=>'running']);$this->task($run,$component?:'planning',$component?:'planning',$input);$p->update(['status'=>'running']);Events::add($p->id,'run.started',['stage'=>$component?:'planning']);DB::afterCommit(fn()=>$this->pump($run->id));return $run;});
 }
 public function expand(Context $ctx,array $out): void {
 if($ctx->task->agent!=='planning')return;$r=$ctx->run();$r->update(['plan'=>$out]);$search=[];foreach($out['perspectives']as $i=>$p)foreach(['crossref','openalex']as $provider)$search[]=$this->task($r,"search-$i-$provider",'discovery',['query'=>$p['query'],'provider'=>$provider],[$ctx->task->id])->id;
 $lit=$this->task($r,'literature','literature',[],$search);$verify=$this->task($r,'verification','verification',[],$search);$invest=[];foreach($out['perspectives']as $i=>$p)$invest[]=$this->task($r,'perspective-'.$i,'investigation',['perspective'=>$p],[$lit->id,$verify->id])->id;
 $prev=$this->task($r,'gaps','gaps',[],$invest);foreach(['experiments','editor','manuscript','citations','quality','collaboration','integrity']as $agent)$prev=$this->task($r,$agent,$agent,[],[$prev->id]);
 }
 public function revisit(Context $ctx,array $out): void {
 if($ctx->task->agent!=='investigation'||empty($out['revisit_query'])||Task::where('run_id',$ctx->task->run_id)->where('key','revisit-search')->exists())return;
 $r=$ctx->run();$search=$this->task($r,'revisit-search','discovery',['query'=>$out['revisit_query'],'provider'=>'crossref'],[$ctx->task->id]);$lit=$this->task($r,'revisit-literature','literature',[],[$search->id]);$verify=$this->task($r,'revisit-verification','verification',[],[$search->id]);$again=$this->task($r,'revisit-investigation','investigation',['perspective'=>['name'=>'Revisit changed evidence','query'=>$out['revisit_query']]],[$lit->id,$verify->id]);$gap=Task::where('run_id',$r->id)->where('key','gaps')->first();if($gap)DB::table('research_dependencies')->insertOrIgnore(['task_id'=>$gap->id,'depends_on'=>$again->id,'created_at'=>now(),'updated_at'=>now()]);
 }
 public function pump(int $id): void {
 Transactions::run(function()use($id){$hint=Run::find($id);if(!$hint)return;$p=Project::whereKey($hint->project_id)->lockForUpdate()->firstOrFail();$r=Run::whereKey($id)->lockForUpdate()->firstOrFail();if($r->status!=='running'||$p->generation!==$r->generation)return;
 $running=Task::where('run_id',$id)->whereIn('status',['queued','running'])->count();$inference=Task::where('run_id',$id)->whereIn('status',['queued','running'])->whereNotIn('agent',['discovery','verification','citations','manuscript','integrity','collaboration'])->exists();
 $pending=Task::where('run_id',$id)->where('status','pending')->where(fn($q)=>$q->whereNull('available_at')->orWhere('available_at','<=',now()))->orderBy('id')->get();foreach($pending as $t){if($running>=config('research.concurrency'))break;$deps=DB::table('research_dependencies')->where('task_id',$t->id)->pluck('depends_on');if(Task::whereIn('id',$deps)->where('status','!=','completed')->exists())continue;$llm=!in_array($t->agent,['discovery','verification','citations','manuscript','integrity','collaboration'],true);if($llm&&$inference)continue;$t->update(['status'=>'queued','lease_until'=>now()->addSeconds(config('research.lease_seconds'))]);ExecuteTask::dispatch($t->id)->onConnection('database')->onQueue('research')->afterCommit();$running++;if($llm)$inference=true;}
 $tasks=Task::where('run_id',$id)->get();if($tasks->every(fn($t)=>$t->status==='completed')){$r->update(['status'=>'completed']);$p->update(['status'=>'awaiting_approval']);Events::add($p->id,'run.completed',['status'=>'awaiting_approval']);}elseif(!$running&&$tasks->contains(fn($t)=>$t->status==='failed')){$r->update(['status'=>'failed']);$p->update(['status'=>'attention']);Events::add($p->id,'run.failed',['error_code'=>'task_failed']);}
 });
 }
 public function control(Project $project,string $action): void {
 Transactions::run(function()use($project,$action){$p=Project::whereKey($project->id)->lockForUpdate()->firstOrFail();$r=Run::where('project_id',$p->id)->latest('id')->lockForUpdate()->first();abort_unless($r,409,'No research run.');if($action==='resume'){abort_unless(in_array($r->status,['paused','cancelled','failed'],true),409);$p->increment('generation');$r->update(['status'=>'running','generation'=>$p->generation]);Task::where('run_id',$r->id)->whereIn('status',['failed','cancelled','running','queued'])->update(['status'=>'pending','claim'=>null,'errors'=>0,'lease_until'=>null,'available_at'=>null,'error_code'=>null]);$p->update(['status'=>'running']);}else{abort_unless($r->status==='running'||$action==='cancel',409);$p->increment('generation');$r->update(['status'=>$action==='pause'?'paused':'cancelled']);Task::where('run_id',$r->id)->whereIn('status',['queued','running','pending'])->update(['status'=>$action==='pause'?'pending':'cancelled','claim'=>null,'lease_until'=>null]);$p->update(['status'=>$r->status]);}Events::add($p->id,'run.control',['status'=>$r->status]);DB::afterCommit(fn()=>$this->pump($r->id));});
 }
 public function recover(): void {
 Task::whereIn('status',['running','queued'])->where('lease_until','<',now())->orderBy('id')->eachById(function($task){Transactions::run(function()use($task){Project::whereKey($task->project_id)->lockForUpdate()->firstOrFail();Run::whereKey($task->run_id)->lockForUpdate()->firstOrFail();$t=Task::whereKey($task->id)->lockForUpdate()->firstOrFail();if(!in_array($t->status,['running','queued'])||$t->lease_until?->isFuture())return;$errors=$t->errors+1;$t->update(['status'=>$errors>=config('research.attempts')?'failed':'pending','errors'=>$errors,'claim'=>null,'lease_until'=>null,'error_code'=>'worker_interrupted']);Events::add($t->project_id,'task.recovered',['task_id'=>$t->id,'status'=>$t->status,'error_code'=>'worker_interrupted']);});});
 Run::where('status','running')->pluck('id')->each(fn($id)=>$this->pump($id));
 }
}
