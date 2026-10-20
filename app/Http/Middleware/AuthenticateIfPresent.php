<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIfPresent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();
        if ($user?->is_active && \App\Support\TenantAccess::permits($request, $user, $request->attributes->get('tenant_vendor'))) {
            $request->setUserResolver(fn () => $user);
        }

        return $next($request);
    }
}
