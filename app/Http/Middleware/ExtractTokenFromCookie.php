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
        if ($request->headers->has('Authorization')) {
            return $next($request);
        }

        $rawToken = $request->cookie('gw_token')
            ?? $request->cookie('token')
            ?? $request->cookie('auth_token')
            ?? $request->cookie('access_token')
            ?? $request->cookies->get('gw_token')
            ?? $request->cookies->get('token')
            ?? $request->cookies->get('auth_token')
            ?? $request->header('X-Auth-Token')
            ?? $request->header('X-Token')
            ?? $request->header('X-Access-Token')
            ?? $request->query('token')
            ?? $request->query('auth_token');

        if (empty($rawToken)) {
            $sessionCookie = $request->cookie('gw_session') ?? $request->cookies->get('gw_session');
            if (! empty($sessionCookie) && is_string($sessionCookie)) {
                try {
                    $decoded = json_decode(base64_decode(strtr($sessionCookie, '-_', '+/')), true);
                    if (is_array($decoded) && ! empty($decoded['token'])) {
                        $rawToken = $decoded['token'];
                    }
                } catch (\Throwable) {
                    // Ignore parsing error
                }
            }
        }

        if (! empty($rawToken) && is_string($rawToken)) {
            $token = trim($rawToken, "\"' \t\n\r\0\x0B");

            // If token was encrypted by Laravel EncryptCookies, decrypt it seamlessly
            try {
                $decrypted = app('encrypter')->decrypt($token, false);
                if (is_string($decrypted) && ! empty($decrypted)) {
                    $token = $decrypted;
                }
            } catch (\Throwable) {
                // Was not encrypted, continue with raw token
            }

            if (! empty($token)) {
                $request->headers->set('Authorization', 'Bearer ' . $token);
            }
        }

        return $next($request);
    }
}
