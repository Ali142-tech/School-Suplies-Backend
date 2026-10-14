<?php

namespace App\Filament\Support;

use App\Models\StoreDomain;
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
            ->modalDescription('Assigns the storefront slug and creates its platform subdomain. DNS/Vercel must be configured manually, then activate the domain.')
            ->schema([
                TextInput::make('slug')->label('Storefront slug')->required()->maxLength(63)
                    ->helperText('Lowercase letters, numbers and hyphens. Becomes {slug}.'.config('platform.domain'))
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

    public static function addCustomDomain(): Action
    {
        return Action::make('addCustomDomain')
            ->label('Assign custom domain')->icon('heroicon-o-plus')
            ->visible(fn (?Vendor $record) => $record?->status === 'active')
            ->schema([TextInput::make('hostname')->required()->maxLength(253)->placeholder('shop.example.com')])
            ->action(fn (Vendor $record, array $data) => self::guard(fn () => app(VendorReviewService::class)->addCustomDomain($record, $data['hostname']), 'Domain assigned (pending activation)'));
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'active' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'gray',
        };
    }
}
