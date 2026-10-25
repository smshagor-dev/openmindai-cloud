<?php

namespace App\Services\Research;

use App\Models\Research\Document;
use App\Models\Research\Evidence;
use App\Models\Research\Experiment;
use App\Models\Research\Project;
use App\Models\Research\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Documents
{
    public function validate(int $project, array $doc): array
    {
        $count = 0;
        $sources = [];
        $figures = [];
        $walk = function ($n, $depth = 0) use (&$walk, &$count, &$sources, &$figures) {
            if (++$count > 12000 || $depth > 30 || ! is_array($n)) {
                throw ValidationException::withMessages(['content' => 'Document limit exceeded.']);
            }
            $type = $n['type'] ?? '';
            $allowed = ['doc', 'paragraph', 'heading', 'text', 'bulletList', 'orderedList', 'listItem', 'blockquote', 'codeBlock', 'hardBreak', 'horizontalRule', 'table', 'tableRow', 'tableCell', 'tableHeader', 'citation', 'math', 'footnote', 'figure', 'crossReference'];
            if (! in_array($type, $allowed, true)) {
                throw ValidationException::withMessages(['content' => 'Unsupported document node.']);
            }
            $out = ['type' => $type];
            if ($type === 'text') {
                $out['text'] = mb_substr((string) ($n['text'] ?? ''), 0, 200000);
            }
            if (isset($n['marks'])) {
                $out['marks'] = array_values(array_filter($n['marks'], fn ($m) => in_array($m['type'] ?? '', ['bold', 'italic', 'strike', 'code', 'underline'], true)));
            }
            $keys = match ($type) {
                'heading' => ['level', 'id'],'citation' => ['sourceId'],'math' => ['latex', 'id'],'footnote' => ['text'],'figure' => ['artifactId', 'caption', 'id'],'crossReference' => ['target'],'table' => ['id', 'caption'],'orderedList' => ['start'],'tableCell','tableHeader' => ['colspan', 'rowspan'],default => []
            };
            if ($keys) {
                $a = array_intersect_key($n['attrs'] ?? [], array_flip($keys));
                foreach ($a as $key => $v) {
                    if ($v === null) {
                        unset($a[$key]);

                        continue;
                    }
                    if (! is_scalar($v) || strlen((string) $v) > 4000) {
                        throw ValidationException::withMessages(['content' => 'Invalid attributes.']);
                    }
                }$out['attrs'] = $a;
            }
            if ($type === 'figure') {
                $figures[] = (int) ($out['attrs']['artifactId'] ?? 0);
            }
            if ($type === 'citation') {
                $sources[] = (int) ($out['attrs']['sourceId'] ?? 0);
            }
            if (isset($n['content'])) {
                if (! is_array($n['content'])) {
                    throw ValidationException::withMessages(['content' => 'Invalid content.']);
                }$out['content'] = array_map(fn ($v) => $walk($v, $depth + 1), $n['content']);
            }

            return $out;
        };
        $clean = $walk($doc);
        abort_unless(($clean['type'] ?? '') === 'doc', 422, 'Document root required.');
        abort_unless(Source::where('project_id', $project)->whereIn('id', array_unique($sources))->count() === count(array_unique($sources)), 422, 'Citations must belong to this project.');

        abort_unless(ExperimentArtifacts::figures($project)->whereIn('id', array_unique($figures))->count() === count(array_unique($figures)), 422, 'Figures must belong to this project.');

        return $clean;
    }

    public function save(Project $p, int $actor, array $content, int $version, string $kind = 'manual'): Document
    {
        $clean = $this->validate($p->id, $content);

        return Transactions::run(function () use ($p, $actor, $clean, $version, $kind) {
            Project::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $d = Document::where('project_id', $p->id)->lockForUpdate()->firstOrFail();
            abort_unless($d->version === $version, 409, 'Document changed. Keep your local draft and review the newer revision.');
            $d->update(['content' => $clean, 'version' => $version + 1]);
            DB::table('research_revisions')->insert(['project_id' => $p->id, 'actor_id' => $actor, 'version' => $d->version, 'kind' => $kind, 'content' => json_encode($clean, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('research_citations')->where('project_id', $p->id)->delete();
            $ids = [];
            $walk = function ($n) use (&$walk, &$ids) {
                if (($n['type'] ?? '') === 'citation') {
                    $ids[] = (int) $n['attrs']['sourceId'];
                }foreach ($n['content'] ?? [] as $c) {
                    $walk($c);
                }
            };
            $walk($clean);
            foreach ($ids as $i => $id) {
                DB::table('research_citations')->insert(['project_id' => $p->id, 'source_id' => $id, 'position' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }Events::add($p->id, 'document.saved', ['version' => $d->version]);

            return $d;
        });
    }

    public function hash(int $id): string
    {
        return hash('sha256', json_encode([Document::where('project_id', $id)->value('content'), Project::findOrFail($id)->settings, Source::where('project_id', $id)->orderBy('id')->get(['id', 'title', 'doi', 'authors', 'year', 'venue', 'verification', 'content_hash', 'metadata'])->toArray(), Evidence::where('project_id', $id)->orderBy('id')->get()->toArray(), Experiment::where('project_id', $id)->orderBy('id')->get()->toArray()], JSON_UNESCAPED_UNICODE));
    }
}
