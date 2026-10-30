<?php

namespace App\Http\Controllers;

use App\Services\AdminSettings;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSettingsController
{
    public function index(AdminSettings $settings)
    {
        return view('admin.settings', ['settings' => $settings->all()]);
    }

    public function update(Request $request, AdminSettings $settings, TwoFactor $twoFactor)
    {
        $data = $request->validate([
            'site_name' => 'required|string|max:60',
            'support_email' => 'nullable|email|max:254',
            'dashboard_note' => 'nullable|string|max:220',
            'registration_enabled' => ['required', Rule::in(['0', '1'])],
            'registration_email_verification_enabled' => ['sometimes', Rule::in(['0', '1'])],
            'unusual_ip_verification_enabled' => ['sometimes', Rule::in(['0', '1'])],
            'guest_chat_daily_limit' => 'sometimes|integer|min:0|max:100',
            'frontend_inference_route' => ['sometimes', Rule::in(['backend', 'direct'])],
            'chat_daily_limit' => 'required|integer|min:1|max:100000',
            'task_daily_limit' => 'required|integer|min:1|max:10000',
            'current_password' => 'required|current_password',
            'code' => 'nullable|string|max:64',
        ]);
        if ($request->user()->two_factor_confirmed_at) {
            abort_unless($twoFactor->verify($request->user(), $data['code'] ?? ''), 422, 'A valid two-factor or recovery code is required.');
        }
        $settings->save(collect($data)->only(array_keys(AdminSettings::DEFAULTS))->all());

        return redirect('/admin/settings')->with('status', 'Workspace settings saved.');
    }
}
