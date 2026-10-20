<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Dedicated buckets so CMS and public-site traffic can never exhaust the login/checkout limits.
        $key = fn (string $name, Request $request) => $name.'|'.($request->user()?->getAuthIdentifier() ?? $request->ip());
        RateLimiter::for('site-public', fn (Request $request) => Limit::perMinute(1200)->by('site-public|'.strtolower((string) ($request->header('X-Store-Slug') ?: $request->header('X-Store-Host') ?: $request->getHost()))));
        RateLimiter::for('cms-write', fn (Request $request) => Limit::perMinute(120)->by($key('cms-write', $request)));
        RateLimiter::for('cms-media', fn (Request $request) => Limit::perMinute(30)->by($key('cms-media', $request)));

        ResetPassword::createUrlUsing(function ($user, string $token) {
            $tenant = request()->attributes->get('tenant_vendor');
            $base = $tenant && \App\Support\StorefrontRouting::isPathMode() ? \App\Support\StorefrontRouting::pathUrl($tenant->slug) : rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

            return $base.'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]);
        });
    }
}
