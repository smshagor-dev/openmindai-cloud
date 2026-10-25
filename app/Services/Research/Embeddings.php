<?php
namespace App\Services\Research;
use App\Models\User;
use App\Http\Controllers\OpenMindAiGatewayController;
use App\Services\{HostedCodeAgents,ApiCreditUsage};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
class Embeddings {
 public function rank(Context $ctx,string $query,array $chunks): array {
 $slug=config('research.embedding_model');$m=DB::table('hosted_code_agents')->where('slug',$slug)->where('enabled',true)->where('api_ready',true)->where('last_test_status','success')->where('api_path','/v1/embeddings')->first();if(!$m)throw new \RuntimeException('verified_embedding_required');
 $user=User::findOrFail($ctx->run()->actor_id);$token=new PersonalAccessToken;$token->forceFill(['abilities'=>['openmindai:invoke']]);$user->withAccessToken($token);
 $vector=function($text,$kind)use($user,$slug){$r=Request::create('/api/v1/openmindai/models/'.$slug.'/invoke','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode(['input'=>['input'=>$text,'input_type'=>$kind]]));$r->setUserResolver(fn()=>$user);$out=app(OpenMindAiGatewayController::class)->invoke($r,$slug,app(HostedCodeAgents::class),app(ApiCreditUsage::class));if($out->getStatusCode()!==200)throw new \RuntimeException('embedding_failed');$v=$out->getData(true)['output']['data'][0]['embedding']??null;if(!is_array($v)||!$v||count($v)>10000)throw new \RuntimeException('embedding_invalid');foreach($v as $n)if(!is_numeric($n)||!is_finite((float)$n))throw new \RuntimeException('embedding_invalid');return $v;};
 $q=$vector($query,'query');foreach($chunks as &$c){$ctx->fenced(fn()=>true);$v=$vector($c['text'],'passage');if(count($v)!==count($q))throw new \RuntimeException('embedding_dimension_mismatch');$dot=$a=$b=0;foreach($q as $i=>$n){$dot+=$n*$v[$i];$a+=$n*$n;$b+=$v[$i]*$v[$i];}$c['semantic_score']=$a&&$b?$dot/sqrt($a*$b):0;}unset($c);usort($chunks,fn($a,$b)=>$b['semantic_score']<=>$a['semantic_score']);$ctx->event('memory.ranked',['model'=>$slug,'count'=>count($chunks)]);return $chunks;
 }
}
