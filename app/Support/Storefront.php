<?php

namespace App\Support;

use App\Models\Vendor;
use Illuminate\Http\Request;

/**
 * Which vendor, if any, a storefront request is confined to.
 *
 * The vendor is never taken from request parameters. It comes from ResolveStoreTenant (an active vendor's slug in path mode, or a
 * verified, active domain) or, for school accounts, from the school's own vendor association.
 */
class Storefront
{
    /** The vendor whose verified domain this request came through, or null on the shared marketplace. */
    public static function tenant(Request $request): ?Vendor
    {
        return $request->attributes->get('tenant_vendor');
    }

    /** Public address of a vendor's storefront under the active routing mode (path or domain); null when it has none yet. */
    public static function url(Vendor $vendor): ?string
    {
        return StorefrontRouting::storefrontUrl($vendor);
    }

    /**
     * The only vendor this request may buy from, or null when the shared multi-vendor marketplace rules apply.
     *  - on a vendor domain: that vendor;
     *  - a school account: always its own vendor, on any endpoint and any domain, and never another vendor's domain.
     */
    public static function purchaseVendor(Request $request): ?Vendor
    {
        $tenant = self::tenant($request);
        $school = $request->user()?->school;
        if ($school) {
            abort_unless($school->is_active, 403, 'This school account is inactive.');
            abort_if($tenant && $tenant->id !== $school->vendor_id, 403, 'This school account belongs to another store.');

            return $school->vendor;
        }

        return $tenant;
    }
}
