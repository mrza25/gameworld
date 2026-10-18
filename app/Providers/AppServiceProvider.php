<?php

declare(strict_types=1);

namespace App\Providers;
use App\Contracts\PaymentGateway;
use App\Services\PaymentGateways\FakePaymentGateway;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict();
        Model::automaticallyEagerLoadRelationships();

        RateLimiter::for('api', function (Request $request) {
            $limit = app()->runningUnitTests() ? 1000 : 120;
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
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
