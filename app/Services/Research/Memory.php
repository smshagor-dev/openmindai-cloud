<?php
namespace App\Services\Research;
use App\Models\Research\Source;
class Memory {
 public function retrieve(Context $ctx,string $query,?int $source=null): array {
 $sources=Source::where('project_id',$ctx->task->project_id)->when($source,fn($q)=>$q->whereKey($source))->get();$terms=preg_split('/[^\pL\pN]+/u',mb_strtolower($query),-1,PREG_SPLIT_NO_EMPTY);$chunks=[];
 foreach($sources as $s){foreach(mb_str_split((string)$s->text,4000)as $i=>$text){$score=0;foreach(array_unique($terms)as $t)$score+=mb_substr_count(mb_strtolower($text),$t);$chunks[]=['source_id'=>$s->id,'title'=>$s->title,'chunk'=>$i,'text'=>$text,'score'=>$score];}}
 usort($chunks,fn($a,$b)=>$b['score']<=>$a['score']);$selected=array_slice($chunks,0,8);
 if(config('research.embedding_model')){try{$vectors=app(Embeddings::class)->rank($ctx,$query,$selected);$selected=$vectors;}catch(StaleExecution $e){throw $e;}catch(\Throwable){$ctx->event('memory.fallback',['error_code'=>'embedding_unavailable','provider'=>'project_lexical']);}}
 return ['method'=>config('research.embedding_model')?'lexical_candidates_optional_semantic_rerank':'project_lexical','coverage'=>'Full source text chunked; at most eight lexical candidates are semantically ranked.','chunks'=>$selected];
 }
}
