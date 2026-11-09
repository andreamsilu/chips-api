<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Payments\PaymentGateway;
use App\Modules\Payments\SnippeGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, SnippeGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Gate::define('administer', fn (User $user) => $user->hasRole('admin'));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()->id ?? $request->ip()));
        RateLimiter::for('authentication', fn (Request $request) => [Limit::perMinute(5)->by(hash('sha256', strtolower((string) $request->input('email')).'|'.$request->ip())), Limit::perMinute(30)->by($request->ip())]);
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id ?? $request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
    }
}
