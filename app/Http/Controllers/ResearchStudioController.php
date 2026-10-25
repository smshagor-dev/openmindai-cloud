<?php

namespace App\Http\Controllers;

use App\Jobs\Research\ExecuteExperiment;
use App\Jobs\Research\ExportDocument;
use App\Jobs\Research\ParseSource;
use App\Models\Research\Document;
use App\Models\Research\Evidence;
use App\Models\Research\Experiment;
use App\Models\Research\Export;
use App\Models\Research\Finding;
use App\Models\Research\Project;
use App\Models\Research\Run;
use App\Models\Research\Source;
use App\Models\Research\Task;
use App\Models\User;
use App\Services\Research\Access;
use App\Services\Research\Documents;
use App\Services\Research\Events;
use App\Services\Research\IntegrityGates;
use App\Services\Research\Orchestrator;
use App\Services\Research\Transactions;
use App\Services\Research\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResearchStudioController
{
    private function access(Request $r, int $id, array $roles = ['owner', 'editor', 'reviewer', 'viewer']): Project
    {
        return app(Access::class)->project($id, $r->user()->id, $roles);
    }

    public function index(Request $r)
    {
        return Project::where('owner_id', $r->user()->id)->orWhereIn('id', DB::table('research_members')->where('user_id', $r->user()->id)->whereNotNull('accepted_at')->select('project_id'))->latest('id')->paginate(30);
    }

    public function create(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'prompt' => 'required|string|max:16000', 'progress_language' => 'sometimes|string|max:40', 'output_language' => 'sometimes|string|max:40']);

        return Transactions::run(function () use ($r, $d) {
            abort_if(Project::where('owner_id', $r->user()->id)->count() >= 100, 422, 'Project limit reached.');
            $p = Project::create(['owner_id' => $r->user()->id, 'title' => $d['title'], 'prompt' => $d['prompt'], 'settings' => ['progress_language' => $d['progress_language'] ?? 'auto', 'output_language' => $d['output_language'] ?? 'auto', 'from_year' => now()->year - 3, 'to_year' => now()->year, 'citation_style' => 'apa', 'journal' => 'generic']]);
            $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
            Document::create(['project_id' => $p->id, 'content' => $doc]);
            DB::table('research_revisions')->insert(['project_id' => $p->id, 'actor_id' => $r->user()->id, 'version' => 1, 'content' => json_encode($doc), 'kind' => 'created', 'created_at' => now(), 'updated_at' => now()]);
            Events::add($p->id, 'project.created', ['status' => 'draft']);

            return response()->json($p, 201);
        });
    }

    public function show(Request $r, int $id)
    {
        $p = $this->access($r, $id);
        $run = Run::where('project_id', $id)->latest('id')->first();
        $tasks = $run ? Task::where('run_id', $run->id)->get(['id', 'run_id', 'key', 'agent', 'status', 'input', 'checkpoint', 'errors', 'error_code', 'created_at', 'updated_at']) : collect();

        return ['project' => $p, 'run' => $run, 'tasks' => $tasks, 'dependencies' => DB::table('research_dependencies')->whereIn('task_id', $tasks->pluck('id'))->get(['task_id', 'depends_on']), 'sources' => Source::where('project_id', $id)->get(['id', 'title', 'doi', 'authors', 'year', 'venue', 'url', 'verification', 'verified_at', 'metadata', 'content_hash']), 'evidence' => Evidence::where('project_id', $id)->get(), 'findings' => Finding::where('project_id', $id)->latest('id')->limit(50)->get(), 'document' => Document::where('project_id', $id)->first(), 'comments' => DB::table('research_comments')->where('project_id', $id)->latest('id')->limit(200)->get(), 'members' => DB::table('research_members')->join('users', 'users.id', '=', 'research_members.user_id')->where('project_id', $id)->get(['research_members.id', 'research_members.user_id', 'role', 'accepted_at', 'users.name']), 'approvals' => DB::table('research_approvals')->where('project_id', $id)->latest('id')->limit(100)->get(), 'experiments' => Experiment::where('project_id', $id)->get(), 'artifacts' => DB::table('research_artifacts')->where('project_id', $id)->get(['id', 'kind', 'name', 'mime', 'bytes', 'sha256', 'experiment_id']), 'exports' => Export::where('project_id', $id)->latest('id')->limit(100)->get(), 'integrity' => app(IntegrityGates::class)->inspect($id), 'event_cursor' => (int) DB::table('research_events')->where('project_id', $id)->max('id')];
    }

    public function settings(Request $r, int $id)
    {
        $p = $this->access($r, $id, ['owner', 'editor']);
        $d = $r->validate(['progress_language' => 'sometimes|string|max:40', 'output_language' => 'sometimes|string|max:40', 'from_year' => 'sometimes|integer|min:1900|max:2100', 'to_year' => 'sometimes|integer|min:1900|max:2100', 'citation_style' => 'sometimes|in:apa,ieee,acm,vancouver,chicago,harvard', 'journal' => 'sometimes|in:generic,ieee,acm,springer,elsevier,custom', 'font_size' => 'sometimes|integer|min:9|max:14', 'margin_mm' => 'sometimes|integer|min:15|max:35', 'columns' => 'sometimes|integer|min:1|max:2', 'models' => 'sometimes|array', 'models.*' => 'string|max:60']);
        $s = array_merge($p->settings, $d);
        abort_unless($s['from_year'] <= $s['to_year'], 422, 'Invalid date range.');
        $p->update(['settings' => $s]);
        Events::add($id, 'project.settings', ['status' => 'updated']);

        return $p;
    }

    public function run(Request $r, int $id)
    {
        $p = $this->access($r, $id, ['owner', 'editor']);

        return app(Orchestrator::class)->start($p, $r->user()->id);
    }

    public function component(Request $r, int $id)
    {
        $p = $this->access($r, $id, ['owner', 'editor']);
        $d = $r->validate(['agent' => 'required|in:question,selection,quality,citations,verification,literature,gaps,editor,manuscript,integrity', 'input' => 'sometimes|array']);
        if (isset($d['input']['source_id'])) {
            Source::where('project_id', $id)->findOrFail($d['input']['source_id']);
        }

return app(Orchestrator::class)->start($p, $r->user()->id, $d['agent'], $d['input'] ?? []);
    }

    public function control(Request $r, int $id)
    {
        $p = $this->access($r, $id, ['owner', 'editor']);
        $d = $r->validate(['action' => 'required|in:pause,resume,cancel']);
        app(Orchestrator::class)->control($p, $d['action']);
        $unconfirmed = [];
        if ($d['action'] === 'cancel') {
            foreach (Experiment::where('project_id', $id)->whereIn('status', ['queued', 'running'])->get() as $x) {
                $x->update(['status' => 'cancelled']);
                try {
                    app(Worker::class)->call('cancel', ['execution_id' => $x->execution_id]);
                } catch (\Throwable) {
                    $unconfirmed[] = $x->id;
                    Events::add($id, 'experiment.cancel_unconfirmed', ['execution_id' => $x->execution_id, 'error_code' => 'worker_unreachable']);
                }
            }
        }

return ['status' => 'accepted', 'unconfirmed_experiment_cancellations' => $unconfirmed];
    }

    public function events(Request $r, int $id)
    {
        $this->access($r, $id);
        $d = $r->validate(['after' => 'sometimes|integer|min:0']);
        $rows = DB::table('research_events')->where('project_id', $id)->where('id', '>', $d['after'] ?? 0)->orderBy('id')->limit(100)->get();

        return response()->stream(function () use ($rows) {
            foreach ($rows as $row) {
                $row->data = json_decode($row->data, true);
                $row->created_at = Carbon::parse($row->created_at)->toIso8601String();
                echo 'id: '.$row->id."\nevent: activity\ndata: ".json_encode($row, JSON_UNESCAPED_UNICODE)."\n\n";
            }echo ": reconnect\n\n";
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'private, no-store, no-transform', 'X-Accel-Buffering' => 'no']);
    }

    public function saveDocument(Request $r, int $id)
    {
        $p = $this->access($r, $id, ['owner', 'editor']);
        $d = $r->validate(['content' => 'required|array', 'version' => 'required|integer|min:1']);

        return app(Documents::class)->save($p, $r->user()->id, $d['content'], $d['version']);
    }

    public function revisions(Request $r, int $id)
    {
        $this->access($r, $id);

        return DB::table('research_revisions')->where('project_id', $id)->latest('version')->limit(100)->get()->map(fn ($x) => array_merge((array) $x, ['content' => json_decode($x->content, true)]));
    }

    public function import(Request $r, int $id)
    {
        $this->access($r, $id, ['owner', 'editor']);
        $d = $r->validate(['records' => 'required|array|min:1|max:50', 'records.*.title' => 'required|string|max:2000', 'records.*.DOI' => 'nullable|string|max:255', 'records.*.author' => 'sometimes|array|max:100', 'records.*.author.*' => 'array', 'records.*.issued' => 'sometimes|array', 'records.*.container-title' => 'nullable|string|max:1000', 'records.*.URL' => 'nullable|url|max:2000']);
        $added = [];
        Transactions::run(function () use ($id, $d, &$added) {
            Project::whereKey($id)->lockForUpdate()->firstOrFail();
            foreach ($d['records'] as $v) {
                abort_if(Source::where('project_id', $id)->count() >= config('research.source_limit'), 422, 'Source quota reached.');
                $doi = mb_strtolower(preg_replace('~^https?://(?:dx\.)?doi\.org/~i', '', trim($v['DOI'] ?? '')));
                $fp = hash('sha256', $doi ?: mb_strtolower(preg_replace('/\s+/u', ' ', trim($v['title']))));
                $s = Source::firstOrCreate(['project_id' => $id, 'fingerprint' => $fp], ['title' => $v['title'], 'doi' => $doi ?: null, 'authors' => $v['author'] ?? [], 'year' => $v['issued']['date-parts'][0][0] ?? null, 'venue' => $v['container-title'] ?? null, 'url' => $v['URL'] ?? ($doi ? 'https://doi.org/'.rawurlencode($doi) : null), 'metadata' => ['provider' => 'user_import']]);
                $added[] = $s->id;
            }Events::add($id, 'sources.imported', ['count' => count($added)]);
        });

        return ['source_ids' => $added];
    }

    public function source(Request $r, int $id, int $source)
    {
        $this->access($r, $id);

        return Source::where('project_id', $id)->findOrFail($source);
    }

    public function sourceUpdate(Request $r, int $id, int $source)
    {
        $this->access($r, $id, ['owner', 'editor']);
        $s = Source::where('project_id', $id)->findOrFail($source);
        $d = $r->validate(['title' => 'sometimes|string|max:2000', 'text' => 'sometimes|string|max:2000000', 'doi' => 'sometimes|nullable|string|max:255', 'authors' => 'sometimes|array|max:100', 'year' => 'sometimes|nullable|integer|min:1500|max:2100', 'venue' => 'sometimes|nullable|string|max:1000']);
        $s->update(array_merge($d, ['verification' => 'unverified', 'verified_at' => null]));

        return $s;
    }

    public function upload(Request $r, int $id)
    {
        $this->access($r, $id, ['owner', 'editor']);
        $d = $r->validate(['kind' => 'required|in:pdf,dataset,figure', 'name' => 'required|string|max:150', 'data' => 'required|string|max:11200000', 'authorized' => 'required|accepted', 'source_id' => 'nullable|integer']);
        $bytes = base64_decode($d['data'], true);
        abort_unless($bytes !== false && strlen($bytes) <= 8000000, 422, 'Invalid upload.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $kind = $d['kind'];
        $ext = match ($kind) {
            'pdf' => 'pdf','dataset' => 'csv',default => $mime === 'image/png' ? 'png' : 'jpg'
        };
        abort_unless(match ($kind) {
            'pdf' => str_starts_with($bytes, '%PDF-'),'figure' => in_array($mime, ['image/png', 'image/jpeg'], true),'dataset' => strlen($bytes) <= 2000000 && ! str_contains($bytes, "\0") && mb_check_encoding($bytes, 'UTF-8')
        }, 422, 'Unsupported file.');

        return Transactions::run(function () use ($id, $d, $bytes, $mime, $kind, $ext) {
            Project::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->quota($id, strlen($bytes));
            $path = 'research/'.$id.'/'.bin2hex(random_bytes(20)).'.'.$ext;
            Storage::disk('local')->put($path, $bytes);
            $hash = hash('sha256', $bytes);
            $artifact = DB::table('research_artifacts')->insertGetId(['project_id' => $id, 'kind' => $kind, 'name' => basename($d['name']), 'mime' => $kind === 'dataset' ? 'text/csv' : ($kind === 'pdf' ? 'application/pdf' : $mime), 'path' => $path, 'sha256' => $hash, 'bytes' => strlen($bytes), 'created_at' => now(), 'updated_at' => now()]);
            $source = null;
            if ($kind === 'pdf') {
                $source = isset($d['source_id']) ? Source::where('project_id', $id)->findOrFail($d['source_id']) : Source::create(['project_id' => $id, 'fingerprint' => hash('sha256', 'upload:'.$hash), 'title' => $d['name'], 'authors' => [], 'metadata' => []]);
                $source->update(['private_path' => $path, 'text' => '', 'metadata' => array_merge($source->metadata, ['upload_sha256' => $hash, 'parse_status' => 'pending'])]);
                ParseSource::dispatch($source->id, $path, $hash)->onConnection('database')->onQueue('research')->afterCommit();
            }Events::add($id, 'upload.saved', ['source_id' => $source?->id, 'status' => $kind === 'pdf' ? 'pending' : 'completed']);

            return ['artifact_id' => $artifact, 'source_id' => $source?->id];
        });
    }

    public function evidence(Request $r, int $id, int $evidence)
    {
        $this->access($r, $id, ['owner', 'editor', 'reviewer']);
        $e = Evidence::where('project_id', $id)->findOrFail($evidence);
        $d = $r->validate(['status' => 'required|in:supported,unsupported,uncertain']);
        $s = Source::where('project_id', $id)->findOrFail($e->source_id);
        abort_unless($d['status'] !== 'supported' || str_contains((string) $s->text, $e->quote), 422, 'Quote is absent from current source text.');
        $e->update(['status' => $d['status'], 'reviewed_by' => $r->user()->id]);
        Events::add($id, 'evidence.reviewed', ['source_id' => $s->id, 'status' => $d['status']]);

        return $e;
    }

    public function comment(Request $r, int $id)
    {
        $this->access($r, $id, ['owner', 'editor', 'reviewer']);
        $d = $r->validate(['body' => 'required|string|max:8000', 'anchor' => 'nullable|string|max:200']);
        $comment = DB::table('research_comments')->insertGetId(['project_id' => $id, 'user_id' => $r->user()->id, 'body' => $d['body'], 'anchor' => $d['anchor'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        Events::add($id, 'comment.created', ['status' => 'recorded']);

        return ['id' => $comment];
    }

    public function invite(Request $r, int $id)
    {
        $this->access($r, $id, ['owner']);
        $d = $r->validate(['email' => 'required|email', 'role' => 'required|in:editor,reviewer,viewer', 'authorized' => 'required|accepted']);
        $user = User::where('email', $d['email'])->firstOrFail();
        $p = Project::findOrFail($id);
        abort_if($user->id === $p->owner_id, 422, 'Owner already has access.');
        DB::table('research_members')->updateOrInsert(['project_id' => $id, 'user_id' => $user->id], ['role' => $d['role'], 'accepted_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        Events::add($id, 'collaboration.invited', ['role' => $d['role']]);

        return ['status' => 'pending_acceptance'];
    }

    public function invitations(Request $r)
    {
        return DB::table('research_members')->join('research_projects', 'research_projects.id', '=', 'project_id')->where('research_members.user_id', $r->user()->id)->whereNull('accepted_at')->get(['research_members.id', 'project_id', 'role', 'research_projects.title']);
    }

    public function accept(Request $r, int $invite)
    {
        $m = DB::table('research_members')->where('id', $invite)->where('user_id', $r->user()->id)->firstOrFail();
        DB::table('research_members')->where('id', $invite)->update(['accepted_at' => now(), 'updated_at' => now()]);
        Events::add($m->project_id, 'collaboration.accepted', ['role' => $m->role]);

        return ['status' => 'accepted'];
    }

    public function revoke(Request $r, int $id, int $member)
    {
        $this->access($r, $id, ['owner']);
        DB::table('research_members')->where('project_id', $id)->where('id', $member)->delete();
        Events::add($id, 'collaboration.revoked', ['status' => 'revoked']);

        return ['status' => 'revoked'];
    }

    public function approval(Request $r, int $id)
    {
        $this->access($r, $id, ['owner', 'reviewer']);
        $d = $r->validate(['gate' => 'required|in:evidence,experiment,manuscript', 'hash' => 'required|string|size:64', 'override' => 'required|boolean', 'reason' => 'required|string|min:10|max:3000']);
        abort_unless(hash_equals(app(Documents::class)->hash($id), $d['hash']), 409, 'Project changed; review current content.');
        if (! $d['override']) {
            abort_unless($d['gate'] === 'manuscript', 422, 'Use source support review and actual executions for verification.');
            $quality = Finding::where('project_id', $id)->where('kind', 'quality')->latest('id')->first();
            abort_unless($quality && ($quality->data['content_hash'] ?? '') === $d['hash'] && ($quality->data['passed'] ?? false), 422, 'A passing quality review of current content is required.');
        }DB::table('research_approvals')->insert(['project_id' => $id, 'user_id' => $r->user()->id, 'gate' => $d['gate'], 'content_hash' => $d['hash'], 'override' => $d['override'], 'reason' => $d['reason'], 'created_at' => now(), 'updated_at' => now()]);
        Events::add($id, 'approval.recorded', ['gate' => $d['gate'], 'status' => $d['override'] ? 'human_override' : 'approved']);

        return app(IntegrityGates::class)->inspect($id);
    }

    public function experiment(Request $r, int $id)
    {
        $this->access($r, $id, ['owner', 'editor']);
        $d = $r->validate(['title' => 'required|string|max:200', 'code' => 'required|string|max:30000', 'dataset_ids' => 'required|array|max:10', 'dataset_ids.*' => 'integer', 'seed' => 'required|integer|min:0|max:2147483647']);
        $ids = array_values(array_unique($d['dataset_ids']));
        abort_unless(DB::table('research_artifacts')->where('project_id', $id)->where('kind', 'dataset')->whereIn('id', $ids)->count() === count($ids), 422, 'Dataset access denied.');
        $hash = hash('sha256', json_encode([$d['code'], $ids, $d['seed']]));

        return Experiment::create(['project_id' => $id, 'title' => $d['title'], 'code' => $d['code'], 'dataset_ids' => $ids, 'seed' => $d['seed'], 'code_hash' => $hash]);
    }

    public function execute(Request $r, int $id, int $experiment)
    {
        $this->access($r, $id, ['owner']);
        $d = $r->validate(['hash' => 'required|string|size:64', 'authorized' => 'required|accepted']);

        return Transactions::run(function () use ($r, $id, $experiment, $d) {
            Project::whereKey($id)->lockForUpdate()->firstOrFail();
            $x = Experiment::where('project_id', $id)->lockForUpdate()->findOrFail($experiment);
            abort_unless($x->status === 'draft' && hash_equals($x->code_hash, $d['hash']), 409, 'Experiment changed or is already submitted.');
            $x->update(['status' => 'queued', 'approved_by' => $r->user()->id, 'execution_id' => 'research-'.bin2hex(random_bytes(16))]);
            DB::table('research_approvals')->insert(['project_id' => $id, 'user_id' => $r->user()->id, 'gate' => 'experiment:'.$x->id, 'content_hash' => $x->code_hash, 'override' => false, 'reason' => 'Explicit authorization for this code, dataset manifest and random seed.', 'created_at' => now(), 'updated_at' => now()]);
            ExecuteExperiment::dispatch($x->id)->onConnection('database')->onQueue('research')->afterCommit();

            return $x;
        });
    }

    public function export(Request $r, int $id)
    {
        $this->access($r, $id, ['owner', 'editor']);
        $d = $r->validate(['format' => 'required|in:docx,pdf,tex,bib,md', 'draft' => 'required|boolean']);
        $g = app(IntegrityGates::class)->inspect($id);
        abort_unless($d['draft'] || $g['passed'], 422, 'Current integrity gates have not passed.');

        return Transactions::run(function () use ($r, $id, $d, $g) {
            Project::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if(Export::where('project_id', $id)->count() >= 100, 422, 'Export count limit.');
            $doc = Document::where('project_id', $id)->firstOrFail();
            $p = Project::findOrFail($id);
            $ids = [];
            $walk = function ($n) use (&$walk, &$ids) {
                if (($n['type'] ?? '') === 'figure') {
                    $ids[] = (int) $n['attrs']['artifactId'];
                }foreach ($n['content'] ?? [] as $c) {
                    $walk($c);
                }
            };
            $walk($doc->content);
            $figures = [];
            foreach (array_unique($ids) as $file) {
                $a = DB::table('research_artifacts')->where('project_id', $id)->where('kind', 'figure')->findOrFail($file);
                $bytes = Storage::disk('local')->get($a->path);
                abort_unless(hash_equals($a->sha256, hash('sha256', $bytes)), 422, 'Figure checksum mismatch.');
                $figures[] = ['id' => $a->id, 'mime' => $a->mime, 'data' => base64_encode($bytes)];
            }$snapshot = ['document' => $doc->content, 'version' => $doc->version, 'sources' => Source::where('project_id', $id)->get(['id', 'title', 'doi', 'authors', 'year', 'venue', 'url'])->toArray(), 'settings' => $p->settings, 'figures' => $figures, 'integrity' => $g, 'draft' => $d['draft'], 'format' => $d['format']];
            $e = Export::create(['project_id' => $id, 'actor_id' => $r->user()->id, 'format' => $d['format'], 'draft' => $d['draft'], 'snapshot' => $snapshot]);
            ExportDocument::dispatch($e->id)->onConnection('database')->onQueue('research')->afterCommit();
            Events::add($id, 'export.queued', ['format' => $d['format'], 'status' => 'queued']);

            return $e;
        });
    }

    public function download(Request $r, int $id, int $file)
    {
        $this->access($r, $id);
        $e = Export::where('project_id', $id)->where('status', 'completed')->findOrFail($file);
        $mime = ['docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'pdf' => 'application/pdf', 'tex' => 'application/zip', 'bib' => 'text/plain', 'md' => 'text/markdown'][$e->format];
        $bytes = Storage::disk('local')->get($e->path);
        abort_unless(hash_equals($e->sha256, hash('sha256', $bytes)), 500, 'Export checksum mismatch.');

        return response($bytes)->header('Content-Type', $mime)->header('Content-Disposition', 'attachment; filename="openmind-research-'.$file.'.'.($e->format === 'tex' ? 'zip' : $e->format).'"')->header('Cache-Control','private, no-store')->header('X-Content-Type-Options','nosniff');
    }

    public function artifact(Request $r,int $id,int $file)
    {
        $this->access($r,$id);
        $a = DB::table('research_artifacts')->where('project_id',$id)->findOrFail($file);
        $bytes = Storage::disk('local')->get($a->path);
        abort_unless(hash_equals($a->sha256,hash('sha256',$bytes)),500,'Artifact checksum mismatch.');
        $mime = in_array($a->mime,['application/pdf', 'image/png', 'image/jpeg', 'text/csv', 'text/plain'],true) ? $a->mime : 'application/octet-stream';

        return response($bytes)->header('Content-Type',$mime)->header('Content-Disposition','attachment; filename="research-'.$file.'"')->header('Cache-Control','private, no-store')->header('X-Content-Type-Options','nosniff');
    }

    private function quota(int $id,int $bytes): void
    {
        abort_if(DB::table('research_artifacts')->where('project_id',$id)->sum('bytes') + Export::where('project_id',$id)->sum('bytes') + $bytes > config('research.storage_bytes'),422,'Project storage quota reached.');
    }
}
