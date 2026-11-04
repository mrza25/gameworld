<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\SmsSender;
use App\Models\User;
use App\Services\PaymentGateways\FakePaymentGateway;
use App\Services\Sms\LogSmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            PaymentGateway::class,
            FakePaymentGateway::class
        );

        $this->app->bind(
            SmsSender::class,
            LogSmsSender::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict();
        Model::automaticallyEagerLoadRelationships();

        RateLimiter::for('api', function (Request $request) {
            $limit = app()->runningUnitTests() ? 1000 : (int) config('services.rate_limits.api_per_minute');

            return Limit::perMinute($limit)->by(auth('sanctum')->user()?->id ?: $request->ip());
        });

        // Password guessing is limited per phone as well as per IP, so rotating IPs does not help.
        RateLimiter::for('login', function (Request $request) {
            $perMinute = (int) config('services.rate_limits.login_per_minute');
            $input = $request->input('phone') ?? $request->input('phonenumber');
            $phone = is_string($input) ? User::normalizePhone($input) : '';

            return [
                Limit::perMinute($perMinute * 2)->by('login-ip:'.$request->ip()),
                Limit::perMinute($perMinute)->by('login-phone:'.$phone),
                Limit::perHour($perMinute * 6)->by('login-phone-hourly:'.$phone),
            ];
        });

        RateLimiter::for('auth', function (Request $request) {
            $limit = app()->runningUnitTests() ? 1000 : 10;

            return Limit::perMinute($limit)->by($request->ip());
        });

        RateLimiter::for('financial', function (Request $request) {
            $limit = app()->runningUnitTests() ? 1000 : 20;

            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });
    }
}
