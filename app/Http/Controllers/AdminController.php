<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadModel;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\MediaTask;
use App\Models\Message;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\AdminSettings;
use App\Services\ApiCreditUsage;
use App\Services\HuggingFace;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminController
{
    public function loginForm(Request $r)
    {
        if ($r->boolean('restart')) {
            $r->session()->forget('admin_challenge');
        }
        $challenge = $r->session()->get('admin_challenge');
        $challenging = is_array($challenge) && ($challenge['expires'] ?? 0) > now()->timestamp;
        if (! $challenging) {
            $r->session()->forget('admin_challenge');
        }

        return view('admin.login', ['challenging' => $challenging]);
    }

    public function login(Request $r, TwoFactor $two)
    {
        $challenge = $r->session()->get('admin_challenge');
        if (is_array($challenge)) {
            $u = User::find($challenge['id'] ?? null);
            if (! $u || ($challenge['expires'] ?? 0) <= now()->timestamp || ! hash_equals($challenge['password_hash'] ?? '', $u->password) || ! $u->is_admin || ! $u->is_active || ! $u->email_verified_at || ! $u->two_factor_confirmed_at) {
                $r->session()->forget('admin_challenge');

                return redirect('/admin/login')->withErrors(['email' => 'Your sign-in session expired. Please sign in again.']);
            }
            $d = $r->validate(['code' => 'required|string|max:64']);
            if (! $two->verify($u, $d['code'])) {
                throw ValidationException::withMessages(['code' => 'Enter a valid authenticator or recovery code.']);
            }
        } else {
            $d = $r->validate(['email' => 'required|email', 'password' => 'required|string|max:1024']);
            $u = User::where('email', mb_strtolower(trim($d['email'])))->first();
            if (! $u || ! Hash::check($d['password'], $u->password) || ! $u->is_admin || ! $u->is_active || ! $u->email_verified_at) {
                throw ValidationException::withMessages(['email' => 'Invalid administrator credentials.']);
            }
            if ($u->two_factor_confirmed_at) {
                $r->session()->regenerate();
                $r->session()->put('admin_challenge', ['id' => $u->id, 'password_hash' => $u->password, 'expires' => now()->addMinutes(5)->timestamp]);

                return redirect('/admin/login');
            }
        }
        $r->session()->forget('admin_challenge');
        Auth::login($u);
        $r->session()->regenerate();
        $r->session()->put('admin_verified', true);

        return redirect('/admin');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/admin/login');
    }

    public function dashboard(AdminSettings $settings, ApiCreditUsage $creditUsage)
    {
        $registrations = [];
        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo);
            $registrations[] = ['day' => $date->format('D'), 'count' => User::whereDate('created_at', $date->toDateString())->count()];
        }

        return view('admin.dashboard', [
            'settings' => $settings->all(),
            'creditModels' => $creditUsage->models(),
            'creditUsers' => $creditUsage->users(),
            'creditMonth' => now()->format('F Y'),
            'stats' => [
                'users' => User::count(),
                'new_users' => User::where('created_at', '>=', now()->subDays(30))->count(),
                'active_users' => User::where('is_active', true)->count(),
                'suspended_users' => User::where('is_active', false)->count(),
                'admins' => User::where('is_admin', true)->count(),
                'conversations' => Conversation::count(),
                'messages' => Message::count(),
                'models' => AiModel::count(),
                'ready_models' => AiModel::where('status', 'ready')->count(),
                'enabled_models' => AiModel::where('status', 'ready')->where('enabled', true)->count(),
                'failed_models' => AiModel::where('status', 'failed')->count(),
                'pending_models' => AiModel::whereIn('status', ['queued', 'downloading'])->count(),
                'running_media' => MediaTask::whereIn('status', ['queued', 'running'])->count(),
                'running_tasks' => TaskRun::whereIn('status', ['queued', 'running'])->count(),
            ],
            'registrations' => $registrations,
            'recentUsers' => User::latest('id')->limit(5)->get(),
            'recentModels' => AiModel::latest('id')->limit(5)->get(),
        ]);
    }

    public function users(Request $r)
    {
        $q = User::query();
        if ($r->filled('q')) {
            $q->where(function ($q) use ($r) {
                $v = '%'.mb_substr($r->input('q'), 0, 120).'%';
                $q->where('name', 'like', $v)->orWhere('email', 'like', $v);
            });
        }

        return view('admin.users', ['users' => $q->latest()->paginate(25)->withQueryString()]);
    }

    public function user(Request $r, User $user, ApiCreditUsage $creditUsage)
    {
        return view('admin.user', ['account' => $user, 'tokens' => $user->tokens()->get(['id', 'name', 'last_used_at', 'created_at']), 'creditModels' => $creditUsage->userModels($user->id), 'creditUsed' => (int) DB::table('api_credit_usage')->where('user_id', $user->id)->where('created_at', '>=', $creditUsage->monthStart())->sum('credits')]);
    }

    public function updateUser(Request $r, User $user)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'is_active' => 'required|boolean']);
        abort_if($user->is_admin, 403, 'Administrator account changes require CLI.');
        $user->name = $d['name'];
        $user->is_active = $d['is_active'];
        $user->save();
        if (! $user->is_active) {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return back()->with('status', 'User updated.');
    }

    public function revoke(Request $r, User $user)
    {
        abort_if($user->is_admin, 403);
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        return back()->with('status', 'All devices revoked.');
    }

    public function models()
    {
        return view('admin.models', ['models' => AiModel::latest()->paginate(20)]);
    }

    public function search(Request $r, HuggingFace $hf)
    {
        $d = $r->validate(['q' => 'required|string|max:100']);

        return view('admin.search', ['results' => $hf->search($d['q']), 'query' => $d['q']]);
    }

    public function inspect(Request $r, HuggingFace $hf)
    {
        $d = $r->validate(['repository' => 'required|string|max:160']);
        $meta = $hf->metadata($d['repository']);

        return view('admin.inspect', ['repository' => $d['repository'], 'revision' => $meta['sha'], 'files' => collect($meta['siblings'] ?? [])->filter(fn ($f) => str_ends_with($f['rfilename'], '.gguf'))]);
    }

    public function import(Request $r, HuggingFace $hf)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'family' => 'required|string|max:80', 'repository' => 'required|string|max:160', 'filename' => 'required|string|max:240', 'revision' => 'required|regex:/^[a-f0-9]{40}$/D', 'license_accepted' => 'required|accepted']);
        $hf->import($d);

        return redirect('/admin/models')->with('status', 'Model imported with a pinned revision and checksum.');
    }

    public function download(AiModel $model)
    {
        $changed = AiModel::whereKey($model->id)->whereIn('status', ['available', 'failed'])->update(['status' => 'queued', 'download_error' => null]);
        abort_unless($changed, 409, 'Model is already queued, downloading, or ready.');
        try {
            DownloadModel::dispatch($model->id);
        } catch (\Throwable $e) {
            $model->update(['status' => 'failed']);
            throw $e;
        }

        return back()->with('status', 'Download queued.');
    }

    public function configure(Request $r, AiModel $model)
    {
        $d = $r->validate(['runtime_model' => 'nullable|string|max:160', 'enabled' => 'required|boolean']);
        abort_if($d['enabled'] && ($model->status !== 'ready' || empty($d['runtime_model'])), 422, 'Download model and configure its runtime ID first.');
        $model->update($d);

        return back()->with('status', 'Runtime mapping saved.');
    }
}
