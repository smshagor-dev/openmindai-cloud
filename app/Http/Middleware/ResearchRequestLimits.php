<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ResearchRequestLimits
{
    public function handle(Request $request, Closure $next)
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            abort_unless($request->isJson(), 415, 'Research API mutations require JSON.');
            abort_if((int) $request->header('Content-Length', 0) > 12000000 || strlen($request->getContent()) > 12000000, 413, 'Research request exceeds the 12 MB limit.');
        }

        return $next($request);
    }
}
