<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebPushController
{
    public function key()
    {
        return response()->json(['public_key' => config('webpush.public_key'), 'configured' => (bool) (config('webpush.public_key') && config('webpush.private_key'))])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r)
    {
        abort_unless(config('webpush.public_key') && config('webpush.private_key'), 503, 'Notifications are not configured.');
        $d = $r->validate(['endpoint' => 'required|url:https|max:2048', 'keys.p256dh' => 'required|string|max:120', 'keys.auth' => 'required|string|max:32']);
        $u = parse_url($d['endpoint']);
        $host = strtolower($u['host'] ?? '');
        abort_unless(! isset($u['user']) && ! isset($u['pass']) && ! isset($u['fragment']) && (! isset($u['port']) || $u['port'] === 443) && (in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com'], true) || str_ends_with($host, '.push.services.mozilla.com') || str_ends_with($host, '.push.apple.com') || str_ends_with($host, '.notify.windows.com')), 422, 'Unsupported push provider endpoint.');
        $decode = fn ($s) => base64_decode(strtr($s, '-_', '+/'), true);
        abort_unless(strlen($decode($d['keys']['p256dh']) ?: '') === 65 && strlen($decode($d['keys']['auth']) ?: '') === 16, 422, 'Invalid subscription keys.');
        DB::transaction(function () use ($r, $d) {
            $hash = hash('sha256', $d['endpoint']);
            abort_if(DB::table('web_push_subscriptions')->where('user_id', $r->user()->id)->where('endpoint_hash', '!=', $hash)->count() >= 10, 422, 'Device subscription limit reached.');
            DB::table('web_push_subscriptions')->updateOrInsert(['endpoint_hash' => $hash], ['user_id' => $r->user()->id, 'endpoint' => $d['endpoint'], 'public_key' => encrypt($d['keys']['p256dh']), 'auth_token' => encrypt($d['keys']['auth']), 'created_at' => now(), 'updated_at' => now()]);
        });

        return ['status' => 'subscribed'];
    }

    public function destroy(Request $r)
    {
        $d = $r->validate(['endpoint' => 'required|string|max:2048']);
        DB::table('web_push_subscriptions')->where('user_id', $r->user()->id)->where('endpoint_hash', hash('sha256', $d['endpoint']))->delete();

        return ['status' => 'unsubscribed'];
    }
}
