<?php
namespace App\Services\Research;
use App\Models\Research\Source;
use Illuminate\Support\Facades\{Http,Cache};
class FullText {
 public function fetch(Context $ctx,Source $source): void {
 if($source->private_path||($source->metadata['fulltext']??false))return;
 $license=json_encode($source->metadata['license']??'');if(!preg_match('~cc-by(?:-sa)?(?:/|-|"|\\\\)|creativecommons.org/licenses/by(?:-sa)?/|cc0|public.domain~i',$license))return;
 $url=$source->metadata['pdf_url']??null;if(!$url){foreach($source->metadata['links']??[] as $link)if(($link['content-type']??'')==='application/pdf'){$url=$link['URL'];break;}}
 if(!$url)return;$p=parse_url($url);$host=$p['host']??'';if(($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['port'])&&$p['port']!==443)throw new \RuntimeException('unsafe_source_url');
 $ips=gethostbynamel($host);if(!$ips)throw new \RuntimeException('source_dns_failed');foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new \RuntimeException('unsafe_source_address');
 abort_unless(Cache::add('research:fetch:'.$host,1,2),429,'Source host rate limit.');$ctx->event('browsing.fetch',['source_id'=>$source->id,'url'=>$url,'domain'=>$host,'title'=>$source->title,'status'=>'reading']);
 $r=Http::timeout(30)->connectTimeout(8)->withOptions(['proxy'=>'','allow_redirects'=>false,'stream'=>true,'curl'=>[CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]]]])->get($url);if(!$r->successful())throw new \RuntimeException('source_fetch_failed');$s=$r->toPsrResponse()->getBody();$bytes='';try{while(!$s->eof()){$bytes.=$s->read(65536);if(strlen($bytes)>8000000)throw new \RuntimeException('source_pdf_limit');}}finally{$s->close();}if(!str_starts_with($bytes,'%PDF-'))throw new \RuntimeException('source_not_pdf');
 $parsed=app(Worker::class)->call('extract',['data'=>base64_encode($bytes)]);$text=$parsed['text']??'';abort_unless(is_string($text)&&strlen($text)<=2000000,502);$ctx->fenced(fn()=>$source->update(['text'=>$text,'metadata'=>array_merge($source->metadata,['fulltext'=>true,'fetched_at'=>now()->toIso8601String(),'fulltext_sha256'=>hash('sha256',$bytes)])]));
 }
}
