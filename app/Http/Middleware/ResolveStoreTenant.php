<?php

namespace App\Http\Middleware;

use App\Models\StoreDomain;
use App\Models\Vendor;
use App\Support\StorefrontRouting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant vendor from the storefront the frontend says the request came through. Both inputs are only *claims*; the
 * vendor is always looked up and must be active, so a header can never name anything the database does not vouch for.
 *
 *  - X-Store-Slug  path-based storefronts (`/vendor/{slug}`). Honored only in path mode; the vendor must be active.
 *  - X-Store-Host  hostname storefronts (platform subdomain or custom domain). Requires a verified, active domain mapping of an
 *                  active vendor, in either mode (so custom domains keep working while path mode is the default).
 *
 * If both are sent they must resolve to the same vendor.
 *
 * `store.tenant`            – the request must come through a vendor storefront (school routes, public site API).
 * `store.tenant:optional`   – shared endpoints: with neither header the request is a marketplace request and nothing is resolved;
 *                             with one, it is resolved just as strictly (an unknown or inactive store is a 404, never a silent
 *                             fallback to the marketplace catalog).
 */
class ResolveStoreTenant
{
    private const SLUG_REGEX = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $slugHeader = strtolower(trim((string) $request->header('X-Store-Slug')));
        $hostHeader = strtolower(rtrim(trim((string) $request->header('X-Store-Host')), '.'));

        if ($mode === 'optional' && $slugHeader === '' && $hostHeader === '') {
            $request->attributes->set('tenant_vendor', null);

            return $next($request);
        }
        if ($slugHeader === '' && $hostHeader === '') {
            $hostHeader = strtolower(rtrim($request->getHost(), '.'));   // direct hit on a store domain (no frontend in between)
        }

        $vendors = [];
        if ($slugHeader !== '') {
            abort_unless(StorefrontRouting::isPathMode(), 404, 'Store not found.');
            $vendors[] = $this->bySlug($slugHeader);
        }
        if ($hostHeader !== '') {
            $vendors[] = $this->byHost($hostHeader);
        }
        abort_unless(count(array_unique(array_map(fn (Vendor $v) => $v->id, $vendors))) === 1, 404, 'Store not found.');

        $request->attributes->set('tenant_vendor', $vendors[0]);

        return $next($request);
    }

    private function bySlug(string $slug): Vendor
    {
        abort_unless(strlen($slug) <= 63 && preg_match(self::SLUG_REGEX, $slug), 404, 'Store not found.');
        $vendor = Vendor::where('slug', $slug)->where('status', 'active')->first();
        abort_unless($vendor, 404, 'Store is not active.');

        return $vendor;
    }

    private function byHost(string $host): Vendor
    {
        abort_unless(filter_var($host, FILTER_VALIDATE_IP) === false && preg_match(StorefrontRouting::HOSTNAME_REGEX, $host), 404, 'Store domain not found.');
        $domain = StoreDomain::with('vendor')->where('hostname', $host)->where('is_verified', true)->where('provisioning_status', 'active')->first();
        abort_unless($domain && $domain->vendor && $domain->vendor->status === 'active', 404, 'Store domain is not active.');

        return $domain->vendor;
    }
}
