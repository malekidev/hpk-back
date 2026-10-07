<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBridge
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('tracker.bridge_token');
        abort_if(strlen($expected) < 32, 503, 'Bridge authentication is not configured.');
        abort_unless(hash_equals($expected, (string) $request->bearerToken()), 401, 'Unauthenticated.');

        return $next($request);
    }
}
