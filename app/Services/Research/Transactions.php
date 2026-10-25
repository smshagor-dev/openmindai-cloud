<?php
namespace App\Services\Research;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
class Transactions {
 public static function run(callable $fn): mixed {for($i=0;;$i++){try{return DB::transaction($fn);}catch(QueryException $e){if($i>=7 || !preg_match('/locked|deadlock|serialization/i',$e->getMessage()))throw $e; usleep(random_int(10000,60000)*(1+$i));}}}
}
