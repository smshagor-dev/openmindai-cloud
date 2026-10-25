<?php
use App\Models\Research\{Project,Run,Task};
use App\Models\User;
use App\Services\Research\{Context,Orchestrator,Scholarly};
use Illuminate\Support\Facades\DB;
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
DB::statement('PRAGMA busy_timeout=5000');
if($argv[1]==='init'){
 DB::statement('PRAGMA journal_mode=WAL');
 $u=User::create(['name'=>'Concurrent fixture','email'=>'concurrent@example.test','password'=>'StrongPassword123']);
 $p=Project::create(['owner_id'=>$u->id,'title'=>'Concurrency fixture','prompt'=>'Synthetic fixture','settings'=>[],'generation'=>1]);
 $r=Run::create(['project_id'=>$p->id,'actor_id'=>$u->id,'generation'=>1]);$ids=[];
 foreach([1,2]as $i){$t=app(Orchestrator::class)->task($r,'concurrent-'.$i,'discovery',['provider'=>'crossref','query'=>'Fixture']);$t->update(['status'=>'running','claim'=>'claim-'.$i]);$ids[]=$t->id;}
 echo json_encode(['project'=>$p->id,'tasks'=>$ids]);return;
}
$t=Task::findOrFail((int)$argv[2]);$dir=$argv[3];$started=microtime(true);touch($dir.'/ready-'.$t->id);
while(!file_exists($dir.'/go')){if(microtime(true)-$started>10)throw new RuntimeException('barrier_timeout');usleep(10000);}
$ctx=new Context($t,$t->claim);
$s=app(Scholarly::class)->save($ctx,['title'=>'Concurrent source fixture','doi'=>'10.1234/concurrent','authors'=>[],'year'=>2025,'venue'=>null,'url'=>'https://doi.org/10.1234/concurrent','text'=>'Actual fixture data','metadata'=>['provider'=>'fixture']]);
$ctx->fenced(function($task){usleep(150000);$task->update(['status'=>'completed','claim'=>null]);});
echo json_encode(['source_id'=>$s->id,'started'=>$started,'finished'=>microtime(true)]);
