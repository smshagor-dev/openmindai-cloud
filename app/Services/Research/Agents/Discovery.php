<?php
namespace App\Services\Research\Agents;
use App\Services\Research\{Context,Scholarly,FullText,StaleExecution};
use App\Models\Research\Source;
class Discovery extends BaseAgent {
 protected array $tools=['crossref.search','openalex.search','licensed_pdf.read'];protected array $inputs=['query'=>'required|string|max:300','provider'=>'required|in:crossref,openalex'];protected array $rules=['source_ids'=>'required|array'];
 public function execute(Context $ctx): array {$s=$ctx->project()->settings;$from=(int)($s['from_year']??now()->year-3);$to=(int)($s['to_year']??now()->year);$provider=$ctx->task->input['provider'];try{$ids=app(Scholarly::class)->search($ctx,$ctx->task->input['query'],$provider,$from,$to);}catch(StaleExecution $e){throw $e;}catch(\Throwable){$ctx->event('browsing.error',['provider'=>$provider,'error_code'=>'index_unavailable','status'=>'failed']);$ids=app(Scholarly::class)->search($ctx,$ctx->task->input['query'],$provider==='crossref'?'openalex':'crossref',$from,$to);}
 foreach(array_slice($ids,0,2)as $id){try{app(FullText::class)->fetch($ctx,Source::findOrFail($id));}catch(StaleExecution $e){throw $e;}catch(\Throwable){$ctx->event('browsing.error',['source_id'=>$id,'error_code'=>'fulltext_unavailable']);}}return ['source_ids'=>$ids];}
}
