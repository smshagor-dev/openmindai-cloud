<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SocialProvider
{
    public const NAMES = ['google', 'facebook'];

    public static function enabled(): array
    {
        return array_values(array_filter(self::NAMES, fn ($provider) => config("social.$provider.id") && config("social.$provider.secret")));
    }

    public function redirectUri(string $provider): string
    {
        $origin = rtrim((string) config('app.frontend_url'), '/');
        abort_unless(filter_var($origin, FILTER_VALIDATE_URL) && str_starts_with($origin, 'https://'), 503, 'Social sign-in needs an HTTPS frontend URL.');

        return "$origin/api/auth/social/$provider/callback";
    }

    public function authorizationUrl(string $provider, array $data): string
    {
        $google = $provider === 'google';
        $url = $google ? 'https://accounts.google.com/o/oauth2/v2/auth' : 'https://www.facebook.com/'.$this->facebookVersion().'/dialog/oauth';
        $params = [
            'client_id' => config("social.$provider.id"),
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            'scope' => $google ? 'openid email profile' : 'email,public_profile',
            'state' => $data['state'],
        ];
        if ($google) {
            $params += ['code_challenge' => $data['challenge'], 'code_challenge_method' => 'S256', 'prompt' => 'select_account'];
        }

        return $url.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function profile(string $provider, string $code, string $verifier): array
    {
        $google = $provider === 'google';
        $graph = $google ? null : 'https://graph.facebook.com/'.$this->facebookVersion();
        $params = [
            'client_id' => config("social.$provider.id"),
            'client_secret' => config("social.$provider.secret"),
            'redirect_uri' => $this->redirectUri($provider),
            'code' => $code,
        ];
        $http = Http::acceptJson()->connectTimeout(4)->timeout(8)->withoutRedirecting();
        // An authorization code is exchanged server-to-server with our own client credentials.
        try {
            $response = $google
                ? $http->asForm()->post('https://oauth2.googleapis.com/token', $params + ['grant_type' => 'authorization_code', 'code_verifier' => $verifier])
                : $http->get($graph.'/oauth/access_token', $params);
        } catch (ConnectionException) {
            abort(503, 'The sign-in provider could not be reached.');
        }
        $token = $response->json('access_token');
        abort_unless($response->successful() && is_string($token) && $token !== '', 422, 'The provider did not authorize this sign-in.');

        try {
            $profile = $google
                ? $http->withToken($token)->get('https://openidconnect.googleapis.com/v1/userinfo')
                : $http->withToken($token)->get($graph.'/me', [
                    'fields' => 'id,name,email',
                    'appsecret_proof' => hash_hmac('sha256', $token, (string) config('social.facebook.secret')),
                ]);
        } catch (ConnectionException) {
            abort(503, 'The sign-in provider could not be reached.');
        }
        $subject = $profile->json($google ? 'sub' : 'id');
        abort_unless($profile->successful() && is_string($subject) && $subject !== '' && strlen($subject) <= 255, 422, 'The provider did not return an account identifier.');
        $email = mb_strtolower(trim((string) $profile->json('email', '')));
        $domain = substr(strrchr($email, '@') ?: '', 1);

        return [
            'subject_hash' => hash('sha256', $subject),
            'email' => strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'name' => mb_substr(trim((string) $profile->json('name', '')) ?: 'OpenMindAI user', 0, 120),
            // Google is authoritative for Gmail and matching Workspace domains.
            'email_verified' => $google && $profile->json('email_verified') === true &&
                ($domain === 'gmail.com' || ($domain !== '' && $profile->json('hd') === $domain)),
        ];
    }

    private function facebookVersion(): string
    {
        $version = (string) config('social.facebook.version');
        abort_unless(preg_match('/^v\d{1,2}\.\d{1,2}$/D', $version), 503, 'Invalid Facebook API version.');

        return $version;
    }
}
