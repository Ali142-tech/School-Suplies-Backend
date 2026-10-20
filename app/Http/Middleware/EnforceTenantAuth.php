<?php

namespace App\Http\Middleware;

use App\Support\TenantAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strict tenant authentication for every authenticated route. The tenant is the one resolved from the verified storefront (never from
 * the credential); a token issued in a storefront only works on that storefront, a central token never works on a storefront, and the
 * account itself must be allowed in that context.
 */
class EnforceTenantAuth
{
    public function __construct(private readonly ResolveStoreTenant $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->has('tenant_vendor') ? $request->attributes->get('tenant_vendor') : $this->tenants->resolve($request);
        $request->attributes->set('tenant_vendor', $tenant);
        $user = $request->user();
        abort_unless($user && TenantAccess::permits($request, $user, $tenant), 403, TenantAccess::MESSAGE);

        return $next($request);
    }
}
