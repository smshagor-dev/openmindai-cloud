<?php

namespace App\Http\Controllers;

use App\Services\AdminSettings;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegalPageController
{
    public const SLUGS = 'privacy-policy|terms-and-conditions';

    public function show(string $slug, AdminSettings $settings)
    {
        $page = DB::table('legal_pages')->where('slug', $slug)->first();
        abort_unless($page, 404);

        return ['title' => $page->title, 'body' => $page->body, 'updated_at' => $page->updated_at, 'support_email' => $settings->get('support_email')];
    }

    public function edit()
    {
        return view('admin.legal-pages', ['pages' => DB::table('legal_pages')->orderBy('slug')->get()]);
    }

    public function update(Request $request, string $slug, TwoFactor $two)
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:100000',
            'current_password' => 'required|current_password',
            'code' => 'nullable|string|max:64',
        ]);
        if ($request->user()->two_factor_confirmed_at) {
            abort_unless($two->verify($request->user(), $data['code'] ?? ''), 422, 'A valid two-factor or recovery code is required.');
        }
        DB::table('legal_pages')->where('slug', $slug)->update([
            'title' => $data['title'], 'body' => $data['body'], 'updated_at' => now(),
        ]);

        return redirect('/admin/legal-pages')->with('status', 'Public page updated.');
    }
}
