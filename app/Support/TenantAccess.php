<?php

namespace App\Support;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Which accounts may authenticate in which context. A context is either one vendor storefront (the tenant the middleware resolved from
 * a verified slug/domain) or the central platform (no tenant). Used for login, password reset and every authenticated request.
 *
 *  - customer          only on the storefront of its own vendor (users.vendor_id); centrally only when it belongs to no vendor
 *  - school            only on the storefront of its school's vendor
 *  - vendor owner/member  on the storefronts of vendors they belong to, and centrally (the vendor portal)
 *  - platform_admin    centrally only
 */
class TenantAccess
{
    public const MESSAGE = 'This session is not valid for this store.';

    public static function allows(User $user, ?Vendor $tenant): bool
    {
        if ($user->role === 'platform_admin') {
            return $tenant === null;
        }
        if ($user->role === 'school') {
            return $tenant !== null && $user->school?->vendor_id === $tenant->id;
        }
        if ($tenant) {
            $belongs = $tenant->owner_id === $user->id || $tenant->memberships()->where('user_id', $user->id)->exists();

            return $belongs || ($user->role === 'customer' && $user->vendor_id === $tenant->id);
        }
        if ($user->role === 'vendor') {
            return true;
        }

        return $user->vendor_id === null || Vendor::where('owner_id', $user->id)->exists() || $user->vendorMemberships()->exists();
    }

    /** Sanctum abilities that bind a token to the context it was issued in. */
    public static function abilities(?Vendor $tenant): array
    {
        return [$tenant ? 'tenant:'.$tenant->id : 'central'];
    }

    public static function issueToken(User $user, ?Vendor $tenant, string $name = 'web'): string
    {
        return $user->createToken($name, self::abilities($tenant))->plainTextToken;
    }

    /** The vendor id a token is bound to, or null for a central token (including legacy tokens issued with the wildcard ability). */
    public static function tokenTenantId(PersonalAccessToken $token): ?int
    {
        foreach ((array) $token->abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'tenant:')) {
                return (int) substr($ability, 7);
            }
        }

        return null;
    }

    /** Does the credential presented with this request belong in this context, and is the account itself allowed there? */
    public static function permits(Request $request, User $user, ?Vendor $tenant): bool
    {
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken && self::tokenTenantId($token) !== $tenant?->id) {
            return false;
        }

        return self::allows($user, $tenant);
    }
}
