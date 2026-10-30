<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class InteractiveSessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        // Keys issued for OpenMindAI integrations must never unlock profile,
        // projects, conversations, tokens, settings, media or admin APIs.
        if ($token instanceof PersonalAccessToken && $token->can('openmindai:invoke') && ! $token->can('*')) {
            abort(403, 'This API key can only access the OpenMindAI model gateway.');
        }

        return $next($request);
    }
}
