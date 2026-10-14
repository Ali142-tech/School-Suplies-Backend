<?php

use App\Http\Middleware\AuthenticateIfPresent;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureApprovedVendor;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\ResolveStoreTenant;
use App\Http\Middleware\ResolveVendor;
use App\Http\Middleware\UseRequestUrlForLocalStorage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(append: [UseRequestUrlForLocalStorage::class]);
        $middleware->alias(['platform.admin' => EnsurePlatformAdmin::class, 'vendor.member' => ResolveVendor::class, 'vendor.approved' => EnsureApprovedVendor::class, 'user.active' => EnsureActiveUser::class, 'auth.optional' => AuthenticateIfPresent::class, 'store.tenant' => ResolveStoreTenant::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->reportable(function (QueryException $e) {
            logger()->error('Database error', [
                'sqlstate' => $e->errorInfo[0] ?? null,
                'code' => $e->getCode(),
                'sql' => $e->getSql(),
                'driver_message' => preg_replace("/'[^']*'/", "'***'", (string) $e->getPrevious()?->getMessage()),
            ]);
        })->stop();
        $exceptions->shouldRenderJsonWhen(fn ($request, $exception) => $request->is('api/*') || $request->expectsJson());
    })->create();
