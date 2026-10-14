<?php

namespace App\Http\Middleware;

use App\Models\StoreDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the vendor from a verified, active domain.
 *
 * `store.tenant`            – the request must come through a vendor domain (school routes, public site API).
 * `store.tenant:optional`   – shared endpoints: with no X-Store-Host header the request is a marketplace request and nothing
 *                             is resolved; with the header the domain is resolved just as strictly (an unverified or inactive
 *                             domain is a 404, never a silent fallback to the marketplace catalog).
 */
class ResolveStoreTenant
{
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        if ($mode === 'optional' && trim((string) $request->header('X-Store-Host')) === '') {
            $request->attributes->set('tenant_vendor', null);

            return $next($request);
        }

        $host = strtolower(rtrim(trim((string) ($request->header('X-Store-Host') ?: $request->getHost())), '.'));
        abort_unless(filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/i', $host), 404, 'Store domain not found.');

        $domain = StoreDomain::with('vendor')->where('hostname', $host)->where('is_verified', true)->where('provisioning_status', 'active')->first();
        abort_unless($domain && $domain->vendor && $domain->vendor->status === 'active', 404, 'Store domain is not active.');

        $request->attributes->set('tenant_vendor', $domain->vendor);

        return $next($request);
    }
}
