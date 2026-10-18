<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExtractTokenFromCookie
{
    /**
     * Handle an incoming request.
     * If an Authorization header is missing but the gw_token cookie is present,
     * inject it as a Bearer token so Sanctum authenticates the request seamlessly.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie('gw_token') ?? $request->cookies->get('gw_token');

        if (! $request->headers->has('Authorization') && ! empty($token) && is_string($token)) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $next($request);
    }
}
