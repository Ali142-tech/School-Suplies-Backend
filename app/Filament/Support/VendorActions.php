<?php

namespace App\Filament\Support;

use App\Models\StoreDomain;
use App\Support\StorefrontRouting;
use App\Models\Vendor;
use App\Services\VendorReviewService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Filament wrappers around VendorReviewService, so the panel and the API share one rule set. */
class VendorActions
{
    public static function guard(Closure $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->title($success)->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title('Validation failed')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
        } catch (HttpExceptionInterface $e) {
            Notification::make()->title('Action not allowed')->body($e->getMessage() ?: 'The request could not be completed.')->danger()->send();
        }
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (Vendor $record) => in_array($record->status, ['pending', 'rejected'], true))
            ->modalDescription(fn () => StorefrontRouting::isPathMode()
                ? 'Assigns the storefront slug. The store goes live at '.StorefrontRouting::pathUrl('{slug}').' immediately; no DNS is needed.'
                : 'Assigns the storefront slug and creates its platform subdomain. DNS/Vercel must be configured manually, then activate the domain.')
            ->schema([
                TextInput::make('slug')->label('Storefront slug')->required()->maxLength(63)
                    ->helperText(StorefrontRouting::isPathMode() ? 'Lowercase letters, numbers and hyphens. Becomes '.StorefrontRouting::pathUrl('{slug}') : 'Lowercase letters, numbers and hyphens. Becomes {slug}.'.config('platform.domain'))
                    ->default(fn (Vendor $record) => $record->requested_slug ?: $record->slug),
            ])
            ->action(fn (Vendor $record, array $data) => self::guard(fn () => app(VendorReviewService::class)->approve($record, $data['slug'], auth()->user()), 'Vendor approved'));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (Vendor $record) => in_array($record->status, ['pending', 'active', 'rejected'], true))
            ->schema([Textarea::make('reason')->label('Rejection reason (sent to the vendor)')->required()->minLength(5)->maxLength(2000)])
            ->action(fn (Vendor $record, array $data) => self::guard(fn () => app(VendorReviewService::class)->reject($record, $data['reason'], auth()->user()), 'Vendor rejected'));
    }

    public static function suspend(): Action
    {
        return Action::make('suspend')
            ->icon('heroicon-o-pause-circle')->color('warning')->requiresConfirmation()
            ->visible(fn (Vendor $record) => $record->status === 'active')
            ->action(fn (Vendor $record) => self::guard(fn () => app(VendorReviewService::class)->suspend($record, auth()->user()), 'Vendor suspended'));
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->icon('heroicon-o-play-circle')->color('success')->requiresConfirmation()
            ->visible(fn (Vendor $record) => $record->status === 'suspended')
            ->action(fn (Vendor $record) => self::guard(fn () => app(VendorReviewService::class)->reactivate($record, auth()->user()), 'Vendor reactivated'));
    }

    public static function activateDomain(): Action
    {
        return Action::make('activateDomain')
            ->label('Activate domain')->icon('heroicon-o-globe-alt')->color('success')
            ->visible(fn (StoreDomain $record) => ! $record->is_verified && $record->vendor?->status === 'active')
            ->schema([Checkbox::make('dns_configured')->label('I confirmed DNS / Vercel is configured for this hostname')->accepted()->required()])
            ->action(fn (StoreDomain $record) => self::guard(fn () => app(VendorReviewService::class)->activateDomain($record), 'Domain activated'));
    }

    public static function unverifyDomain(): Action
    {
        return Action::make('unverifyDomain')
            ->label('Disable domain')->icon('heroicon-o-no-symbol')->color('warning')->requiresConfirmation()
            ->visible(fn (StoreDomain $record) => $record->is_verified)
            ->action(fn (StoreDomain $record) => self::guard(fn () => app(VendorReviewService::class)->setDomainVerified($record, false), 'Domain disabled'));
    }

    /** The vendor an action belongs to: the record on a vendor page, the owner record inside the Domains relation manager. */
    private static function vendorOf(mixed $record, mixed $livewire): ?Vendor
    {
        if ($record instanceof Vendor) {
            return $record;
        }
        $owner = $livewire && method_exists($livewire, 'getOwnerRecord') ? $livewire->getOwnerRecord() : null;

        return $owner instanceof Vendor ? $owner : null;
    }

    public static function addCustomDomain(): Action
    {
        return Action::make('addCustomDomain')
            ->label('Assign custom domain')->icon('heroicon-o-plus')
            ->visible(fn (mixed $record = null, mixed $livewire = null) => self::vendorOf($record, $livewire)?->status === 'active')
            ->modalHeading('Assign a custom domain')
            ->modalDescription('The hostname is saved against this vendor as inactive. It serves nothing until DNS, Vercel and SSL are configured for it and you activate it from the Domains table.')
            ->modalSubmitActionLabel('Assign domain')
            ->schema([
                TextInput::make('hostname')->label('Hostname')->required()->maxLength(253)->placeholder('shop.example.com')
                    ->helperText('A bare hostname such as shop.example.com. A pasted URL is cleaned up automatically.')
                    ->live(onBlur: true)->afterStateUpdated(fn ($state, $set) => $set('hostname', VendorReviewService::normalizeHostname($state)))
                    ->regex(VendorReviewService::HOSTNAME_REGEX)->validationMessages(['regex' => 'Enter a valid hostname such as shop.example.com.'])
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                        if (StoreDomain::where('hostname', VendorReviewService::normalizeHostname($value))->exists()) {
                            $fail('This hostname is already assigned to a store.');
                        }
                    }),
            ])
            ->action(function (array $data, mixed $record = null, mixed $livewire = null) {
                $vendor = self::vendorOf($record, $livewire);
                self::guard(fn () => app(VendorReviewService::class)->addCustomDomain($vendor, $data['hostname']), 'Domain assigned (pending activation)');
            });
    }

    /** {slug}.PLATFORM_DOMAIN for an approved vendor that has no mapping yet (the one-subdomain-per-vendor model). */
    public static function assignPlatformSubdomain(): Action
    {
        return Action::make('assignPlatformSubdomain')
            ->label('Assign platform subdomain')->icon('heroicon-o-globe-alt')->color('primary')
            ->visible(fn (mixed $record = null, mixed $livewire = null) => ($vendor = self::vendorOf($record, $livewire)) && $vendor->status === 'active' && ! $vendor->domains()->where('source', 'platform_subdomain')->exists())
            ->modalHeading('Assign platform subdomain')
            ->modalDescription(function (mixed $record = null, mixed $livewire = null) {
                $vendor = self::vendorOf($record, $livewire);
                $host = $vendor ? StorefrontRouting::platformHostname($vendor->slug) : null;

                return $host
                    ? 'Creates '.$host.' for this vendor as an inactive mapping. Add the wildcard domain in Vercel and DNS first, then activate it from the Domains table.'
                    : 'PLATFORM_DOMAIN is not configured on the API, so no platform subdomain can be created yet.';
            })
            ->requiresConfirmation()
            ->action(function (mixed $record = null, mixed $livewire = null) {
                $vendor = self::vendorOf($record, $livewire);
                self::guard(fn () => app(VendorReviewService::class)->assignPlatformSubdomain($vendor), 'Subdomain assigned (pending activation)');
            });
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'active' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'gray',
        };
    }
}
