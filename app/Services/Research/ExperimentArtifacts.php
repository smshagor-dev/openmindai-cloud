<?php

namespace App\Services\Research;

use App\Models\Research\Experiment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExperimentArtifacts
{
    public static function figures(int $project)
    {
        return DB::table('research_artifacts')->where('project_id', $project)->whereIn('mime', ['image/png', 'image/jpeg'])->where(function ($q) use ($project) {
            $q->where('kind', 'figure')->orWhere(function ($q) use ($project) {
                $q->where('kind', 'experiment')->whereIn('experiment_id', Experiment::where('project_id', $project)->where('status', 'completed')->get()->filter(fn ($x) => $x->verifiedExecution())->pluck('id'));
            });
        });
    }

    public function context(int $project): array
    {
        return Experiment::where('project_id', $project)->latest('id')->limit(20)->get()->map(function ($x) {
            $result = $x->result ?? [];
            foreach (['stdout', 'stderr'] as $key) {
                if (isset($result[$key])) {
                    $result[$key.'_excerpted'] = mb_strlen($result[$key]) > 4000;
                    $result[$key] = mb_substr($result[$key], 0, 4000);
                }
            }

            return ['id' => $x->id, 'title' => $x->title, 'status' => $x->status, 'verified' => $x->verifiedExecution(), 'result' => $result];
        })->all();
    }

    public function nodes(int $project): array
    {
        $nodes = [];
        $runs = Experiment::where('project_id', $project)->where('status', 'completed')->get()->filter(fn ($x) => $x->verifiedExecution());
        foreach (DB::table('research_artifacts')->where('project_id', $project)->whereIn('experiment_id', $runs->pluck('id'))->orderBy('id')->limit(10)->get() as $a) {
            $bytes = Storage::disk('local')->get($a->path);
            if (! hash_equals($a->sha256, hash('sha256', $bytes))) {
                throw new \RuntimeException('artifact_checksum');
            }
            if (in_array($a->mime, ['image/png', 'image/jpeg'], true)) {
                $size = @getimagesizefromstring($bytes);
                if (! $size || $size[0] * $size[1] > 25000000) {
                    continue;
                }
                $nodes[] = ['type' => 'figure', 'attrs' => ['id' => 'experiment-figure-'.$a->id, 'artifactId' => $a->id, 'caption' => 'Executed experiment artifact: '.$a->name]];
            } elseif (str_ends_with(strtolower($a->name), '.csv') && strlen($bytes) <= 200000 && mb_check_encoding($bytes, 'UTF-8')) {
                $rows = [];
                $stream = fopen('php://temp', 'r+');
                fwrite($stream, $bytes);
                rewind($stream);
                for ($i = 0; $i < 51 && ($cells = fgetcsv($stream, 0, ',', '"', '')) !== false; $i++) {
                    $cells = array_slice($cells, 0, 12);
                    $rows[] = ['type' => 'tableRow', 'content' => array_map(fn ($v) => ['type' => $i === 0 ? 'tableHeader' : 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => mb_substr((string) $v, 0, 500)]]]]], $cells)];
                }
                fclose($stream);
                if ($rows) {
                    $nodes[] = ['type' => 'table', 'attrs' => ['id' => 'experiment-table-'.$a->id, 'caption' => 'Executed CSV artifact: '.$a->name.' (preview: up to 50 data rows, 12 columns; full artifact retained)'], 'content' => $rows];
                }
            }
        }

        return $nodes;
    }
}
