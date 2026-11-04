<?php

declare(strict_types=1);

use App\Http\Middleware\ExtractTokenFromCookie;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->trustHosts();
        $middleware->append(SecurityHeaders::class);
        $middleware->encryptCookies(except: [
            'gw_token',
        ]);
        $middleware->api(prepend: [
            ExtractTokenFromCookie::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Give framework-generated API errors (validation, 401, 404, ...) the same
        // { ok: false, error } envelope as the controllers' own error responses.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if (! $request->is('api/*') || ! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
                return $response;
            }

            $data = $response->getData(true);

            if (! is_array($data) || array_key_exists('ok', $data)) {
                return $response;
            }

            $error = match (true) {
                $e instanceof ValidationException => collect($e->errors())->flatten()->first(),
                $e instanceof ThrottleRequestsException => 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.',
                default => $data['message'] ?? null,
            };

            return $response->setData(['ok' => false, 'error' => $error ?: 'خطایی رخ داد.'] + $data);
        });
    })->create();
