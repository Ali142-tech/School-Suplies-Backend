<?php

namespace App\Support;

use App\Models\StoreDomain;
use App\Models\Vendor;

/**
 * How storefront URLs are shaped. Tenant identity (vendor slug, verified domain mapping) is resolved elsewhere and never
 * depends on this; the mode only decides which address a vendor is *published* at and which inputs a request may use.
 */
class StorefrontRouting
{
    public const PATH = 'path';

    public const DOMAIN = 'domain';

    public const HOSTNAME_REGEX = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';

    /** Unknown values fall back to the safe default instead of breaking the storefront. */
    public static function mode(): string
    {
        return strtolower((string) config('platform.storefront.mode')) === self::DOMAIN ? self::DOMAIN : self::PATH;
    }

    public static function isPathMode(): bool
    {
        return self::mode() === self::PATH;
    }

    public static function isDomainMode(): bool
    {
        return self::mode() === self::DOMAIN;
    }

    /** The configured platform apex domain when it is usable ('localhost' is allowed for local development), otherwise null. */
    public static function platformDomain(): ?string
    {
        $domain = strtolower(trim((string) config('platform.domain'), ". \t"));

        return $domain !== '' && ($domain === 'localhost' || preg_match(self::HOSTNAME_REGEX, $domain)) ? $domain : null;
    }

    /** Only domain mode needs a platform domain; path mode works without one. */
    public static function requiresPlatformDomain(): bool
    {
        return self::isDomainMode();
    }

    public static function pathBaseUrl(): ?string
    {
        $base = rtrim(trim((string) config('platform.storefront.base_url')), '/');

        return $base !== '' ? $base : null;
    }

    /** Path-mode address: {frontend}/vendor/{slug}; a relative path when no frontend origin is configured. */
    public static function pathUrl(string $slug): string
    {
        return (self::pathBaseUrl() ?? '').'/'.trim((string) config('platform.storefront.path_prefix'), '/').'/'.$slug;
    }

    /** The public address of a vendor's storefront for the active mode, or null when it has none yet (e.g. no active domain mapping). */
    public static function storefrontUrl(Vendor $vendor): ?string
    {
        if (! $vendor->slug) {
            return null;
        }
        if (self::isPathMode()) {
            return self::pathUrl($vendor->slug);
        }
        $domain = $vendor->domains()->where('is_verified', true)->where('provisioning_status', 'active')->orderByDesc('is_primary')->first();

        return $domain ? self::hostUrl($domain) : null;
    }

    private static function hostUrl(StoreDomain $domain): string
    {
        return (self::platformDomain() === 'localhost' || str_ends_with($domain->hostname, '.localhost') ? 'http://' : 'https://').$domain->hostname;
    }

    /** The platform subdomain a vendor would get in domain mode, or null if no valid platform domain is configured. */
    public static function platformHostname(string $slug): ?string
    {
        $domain = self::platformDomain();

        return $domain ? strtolower($slug).'.'.$domain : null;
    }
}
