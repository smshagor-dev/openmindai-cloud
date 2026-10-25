<?php
namespace App\Services\Research;
use App\Models\Research\Source;
use Illuminate\Support\Facades\{Http,Cache,DB};
class Scholarly {
 private function get(string $provider,string $path,array $params): array {
 $url=($provider==='crossref'?'https://api.crossref.org':'https://api.openalex.org').$path;
 if($provider==='crossref'&&config('research.contact'))$params['mailto']=config('research.contact');if($provider==='openalex'&&config('research.openalex_key'))$params['api_key']=config('research.openalex_key');
 return Cache::remember('research:index:'.hash('sha256',$url.json_encode($params)),600,function()use($url,$params,$provider){abort_unless(Cache::add('research:rate:'.$provider,1,1),429,'Index rate limit.');$r=Http::acceptJson()->timeout(25)->connectTimeout(8)->withOptions(['allow_redirects'=>false,'stream'=>true])->get($url,$params);if(!$r->successful())throw new \RuntimeException('index_unavailable');$stream=$r->toPsrResponse()->getBody();$body='';try{while(!$stream->eof()){ $body.=$stream->read(65536);if(strlen($body)>8000000)throw new \RuntimeException('index_body_limit');}}finally{$stream->close();}return json_decode($body,true,128,JSON_THROW_ON_ERROR);});
 }
 public function search(Context $ctx,string $query,string $provider,int $from,int $to): array {
 $ctx->event('browsing.search',['agent'=>'discovery','query'=>$query,'provider'=>$provider,'status'=>'searching']);
 if($provider==='crossref'){$body=$this->get($provider,'/works',['query'=>$query,'rows'=>12,'filter'=>"from-pub-date:$from-01-01,until-pub-date:$to-12-31",'sort'=>'relevance']);$items=$body['message']['items']??[];}else{$body=$this->get('openalex','/works',['search'=>$query,'per-page'=>12,'filter'=>"from_publication_date:$from-01-01,to_publication_date:$to-12-31"]);$items=$body['results']??[];}
 $ids=[];foreach($items as $item){$data=$this->normalize($item,$provider);if(!$data['title'])continue;$s=$this->save($ctx,$data);$ids[]=$s->id;$ctx->event('browsing.discovered',['source_id'=>$s->id,'title'=>$s->title,'url'=>$s->url,'provider'=>$provider,'status'=>'completed']);}return $ids;
 }
 public function normalize(array $v,string $provider): array {
 if($provider==='crossref')return ['title'=>strip_tags((string)($v['title'][0]??'')),'doi'=>$v['DOI']??null,'authors'=>$v['author']??[],'year'=>$v['published']['date-parts'][0][0]??null,'venue'=>$v['container-title'][0]??null,'url'=>$v['URL']??null,'text'=>strip_tags($v['abstract']??''),'metadata'=>['provider'=>$provider,'license'=>$v['license']??[],'links'=>$v['link']??[],'relations'=>$v['relation']??[],'updates'=>$v['update-to']??[]]];
 $abstract=[];foreach($v['abstract_inverted_index']??[] as $word=>$positions)foreach($positions as $i)$abstract[$i]=$word;ksort($abstract);
 return ['title'=>$v['title']??'','doi'=>isset($v['doi'])?preg_replace('~^https?://(?:dx\.)?doi\.org/~i','',$v['doi']):null,'authors'=>array_map(fn($a)=>['literal'=>$a['author']['display_name']??''],$v['authorships']??[]),'year'=>$v['publication_year']??null,'venue'=>$v['primary_location']['source']['display_name']??null,'url'=>$v['primary_location']['landing_page_url']??$v['id']??null,'text'=>implode(' ',$abstract),'metadata'=>['provider'=>$provider,'retracted'=>$v['is_retracted']??false,'license'=>$v['best_oa_location']['license']??null,'pdf_url'=>$v['best_oa_location']['pdf_url']??null]];
 }
 public function save(Context $ctx,array $data): Source {
 $doi=$data['doi']?mb_strtolower(trim(preg_replace('~^https?://(?:dx\.)?doi\.org/~i','',$data['doi']))):null;$fp=hash('sha256',$doi?:mb_strtolower(preg_replace('/\s+/u',' ',trim($data['title']))));
 return $ctx->fenced(function()use($ctx,$data,$doi,$fp){$existing=Source::where('project_id',$ctx->task->project_id)->where(function($q)use($fp,$doi,$data){$q->where('fingerprint',$fp);if($doi)$q->orWhere('doi',$doi);$q->orWhere('title',$data['title']);})->first();if($existing)return $existing;abort_if(Source::where('project_id',$ctx->task->project_id)->count()>=config('research.source_limit'),422,'Source quota reached.');return Source::create(array_merge($data,['project_id'=>$ctx->task->project_id,'doi'=>$doi,'fingerprint'=>$fp]));});
 }
 public function verify(Context $ctx,Source $s): array {
 if(!$s->doi)return ['status'=>'unverified','reason'=>'doi_missing'];$ctx->event('browsing.verify',['source_id'=>$s->id,'provider'=>'crossref','status'=>'verifying']);
 try{$v=$this->get('crossref','/works/'.rawurlencode($s->doi),[]);$record=$this->normalize($v['message']??[],'crossref');$norm=fn($t)=>mb_strtolower(preg_replace('/[^\pL\pN]+/u','',(string)$t));
 $authors=fn($as)=>array_map(fn($a)=>$norm($a['family']??$a['literal']??''),$as);$sameAuthors=!$s->authors||!array_diff($authors($s->authors),$authors($record['authors']));
 $ok=$norm($s->title)===$norm($record['title'])&&$norm($s->doi)===$norm($record['doi'])&&(!$s->year||$s->year===$record['year'])&&(!$s->venue||$norm($s->venue)===$norm($record['venue']))&&$sameAuthors;
 $bad=($s->metadata['retracted']??false)||array_filter($record['metadata']['updates']??[],fn($u)=>str_contains(strtolower($u['type']??''),'retract'));$status=$bad?'retracted':($ok?'verified':'mismatch');
 $ctx->fenced(function()use($s,$status,$record){$s->update(['verification'=>$status,'verified_at'=>now(),'metadata'=>array_merge($s->metadata,['verification_record'=>$record,'verification_provider'=>'crossref'])]);});return ['source_id'=>$s->id,'status'=>$status,'metadata_match'=>$ok];
 }catch(StaleExecution $e){throw $e;}catch(\Throwable){return ['source_id'=>$s->id,'status'=>'unverified','reason'=>'lookup_failed'];}
 }
}
