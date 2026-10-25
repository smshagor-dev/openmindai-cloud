<?php
namespace App\Services\Research\Agents;
use App\Services\Research\Context;
class SelectionEditor extends BaseAgent {
 protected array $tools=['selection.propose','structured_reasoning'];protected array $inputs=['selection'=>'required|string|max:12000','instruction'=>'required|string|max:2000'];protected array $rules=['suggestion'=>'required|string|max:20000','explanation'=>'required|string'];
 public function execute(Context $ctx): array {return $this->ask($ctx,'Provide a reviewable selection revision or continuation. Preserve technical terminology and all literal citation/equation markers. Do not invent evidence or results. Return suggestion and explanation; this action cannot mutate the manuscript.',['project'=>$this->project($ctx),'selection'=>$ctx->task->input['selection'],'instruction'=>$ctx->task->input['instruction']]);}
}
