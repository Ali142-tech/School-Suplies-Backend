<?php

namespace App\Services;

use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\VendorApplicationReviewed;
use App\Support\Storefront;
use App\Support\StorefrontRouting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for vendor approval, rejection, suspension and domain activation.
 * Used by the JSON admin API and the Filament Super Admin panel.
 */
class VendorReviewService
{
    public const HOSTNAME_REGEX = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';

    public function hostnameRules(): array
    {
        return ['required', 'string', 'max:253', 'regex:'.self::HOSTNAME_REGEX, 'unique:store_domains,hostname'];
    }

    /** Attach a custom (non-platform) hostname to a vendor; verification/activation stays a separate manual step. */
    public function addCustomDomain(Vendor $vendor, string $hostname): StoreDomain
    {
        Validator::make(['hostname' => $hostname], ['hostname' => $this->hostnameRules()])->validate();
        $hostname = strtolower($hostname);
        $platformDomain = strtolower((string) config('platform.domain'));
        abort_if($platformDomain && ($hostname === $platformDomain || str_ends_with($hostname, '.'.$platformDomain)), 422, 'Platform subdomains are assigned by a Super Admin during vendor approval.');

        return $vendor->domains()->create(['hostname' => $hostname, 'is_verified' => false]);
    }

    public function slugRules(Vendor $vendor): array
    {
        return ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('vendors', 'slug')->ignore($vendor->id), function ($attribute, $value, $fail) {
            if (in_array(strtolower($value), config('platform.reserved_slugs'), true)) {
                $fail('This storefront slug is reserved.');
            }
        }];
    }

    public function approve(Vendor $vendor, string $slug, User $reviewer): Vendor
    {
        Validator::make(['slug' => $slug], ['slug' => $this->slugRules($vendor)])->validate();
        // Path mode (default) publishes the store at /vendor/{slug} and needs no DNS, so PLATFORM_DOMAIN is optional there. Domain
        // mode addresses stores by hostname, so it cannot approve anyone without a usable platform domain.
        abort_if(StorefrontRouting::requiresPlatformDomain() && ! StorefrontRouting::platformDomain(), 503, 'Configure PLATFORM_DOMAIN before approving vendor storefronts.');
        // When a platform domain is configured the (still inactive) subdomain mapping is prepared in either mode, so switching modes later needs no migration.
        $hostname = StorefrontRouting::platformHostname($slug);
        $approved = DB::transaction(function () use ($reviewer, $vendor, $slug, $hostname) {
            $locked = Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending' || $locked->status === 'rejected', 409, 'Only pending or rejected applications can be approved.');
            abort_if($hostname && StoreDomain::where('hostname', $hostname)->where('vendor_id', '!=', $locked->id)->exists(), 422, 'This storefront hostname is already assigned.');
            $locked->domains()->where('source', 'platform_subdomain')->delete();
            $locked->update(['slug' => strtolower($slug), 'status' => 'active', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'approved_at' => now(), 'rejected_at' => null, 'rejection_reason' => null]);
            if ($hostname) {
                $locked->domains()->updateOrCreate(['hostname' => $hostname], ['is_verified' => false, 'provisioning_status' => 'manual_pending', 'source' => 'platform_subdomain', 'is_primary' => true, 'verified_at' => null]);
            }

            return $locked->fresh(['owner', 'domains']);
        });
        try {
            $approved->owner->notify(new VendorApplicationReviewed('approved', $hostname, null, StorefrontRouting::isPathMode() ? Storefront::url($approved) : null));
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $approved;
    }

    public function reject(Vendor $vendor, string $reason, User $reviewer): Vendor
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:5|max:2000'])->validate();
        $rejected = DB::transaction(function () use ($reviewer, $vendor, $reason) {
            $locked = Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['pending', 'active', 'rejected'], true), 409, 'This vendor cannot be rejected in its current state.');
            $locked->domains()->where('source', 'platform_subdomain')->delete();
            $locked->domains()->update(['is_verified' => false, 'provisioning_status' => 'rejected']);
            $locked->update(['status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'rejected_at' => now(), 'approved_at' => null, 'rejection_reason' => $reason]);

            return $locked->fresh(['owner']);
        });
        try {
            $rejected->owner->notify(new VendorApplicationReviewed('rejected', null, $reason));
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $rejected;
    }

    public function suspend(Vendor $vendor, User $reviewer): Vendor
    {
        abort_unless($vendor->status === 'active', 409, 'Only active vendors can be suspended.');
        DB::transaction(function () use ($reviewer, $vendor) {
            $locked = Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            $locked->update(['status' => 'suspended', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
            $locked->domains()->update(['is_verified' => false, 'provisioning_status' => 'suspended']);
        });

        return $vendor->fresh();
    }

    public function reactivate(Vendor $vendor, User $reviewer): Vendor
    {
        abort_unless($vendor->status === 'suspended', 409, 'Only suspended vendors can be reactivated.');
        DB::transaction(function () use ($reviewer, $vendor) {
            $locked = Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            $locked->update(['status' => 'active', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
            $locked->domains()->where('source', 'platform_subdomain')->update(['is_verified' => false, 'provisioning_status' => 'manual_pending']);
        });

        return $vendor->fresh();
    }

    public function activateDomain(StoreDomain $domain): StoreDomain
    {
        abort_unless($domain->vendor?->status === 'active', 409, 'The vendor must be approved and active before domain activation.');
        $domain->update(['is_verified' => true, 'verified_at' => now(), 'provisioning_status' => 'active']);

        return $domain->fresh();
    }

    /** Manual verification toggle, as exposed by the existing admin API. */
    public function setDomainVerified(StoreDomain $domain, bool $verified): StoreDomain
    {
        $domain->update(['is_verified' => $verified]);

        return $domain;
    }
}
