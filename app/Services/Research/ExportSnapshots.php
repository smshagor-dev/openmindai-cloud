<?php

namespace App\Services\Research;

use App\Models\Research\Project;
use App\Models\Research\Source;
use Illuminate\Support\Facades\Storage;

class ExportSnapshots
{
    public function make(int $project, array $document, int $version, bool $draft): array
    {
        $clean = app(Documents::class)->validate($project, $document);
        $ids = [];
        $walk = function ($n) use (&$walk, &$ids) {
            if (($n['type'] ?? '') === 'figure') {
                $ids[] = (int) $n['attrs']['artifactId'];
            }foreach ($n['content'] ?? [] as $c) {
                $walk($c);
            }
        };
        $walk($clean);
        $figures = [];
        foreach (array_unique($ids) as $id) {
            $a = ExperimentArtifacts::figures($project)->where('id', $id)->firstOrFail();
            $bytes = Storage::disk('local')->get($a->path);
            abort_unless(hash_equals($a->sha256, hash('sha256', $bytes)), 422, 'Figure checksum mismatch.');
            $figures[] = ['id' => $a->id, 'mime' => $a->mime, 'data' => base64_encode($bytes)];
        }
        $g = app(IntegrityGates::class)->inspect($project);
        abort_unless($draft || $g['passed'], 422, 'Current integrity gates have not passed.');

        return ['document' => $clean, 'version' => $version, 'sources' => Source::where('project_id', $project)->get(['id', 'title', 'doi', 'authors', 'year', 'venue', 'url'])->toArray(), 'settings' => Project::findOrFail($project)->settings, 'figures' => $figures, 'integrity' => $g, 'draft' => $draft];
    }
}
