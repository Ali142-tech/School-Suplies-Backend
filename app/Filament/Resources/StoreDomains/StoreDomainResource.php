<?php

namespace App\Filament\Resources\StoreDomains;

use App\Filament\Resources\StoreDomains\Pages\ListStoreDomains;
use App\Filament\Support\VendorActions;
use App\Models\StoreDomain;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StoreDomainResource extends Resource
{
    protected static ?string $model = StoreDomain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Domains';

    protected static ?string $recordTitleAttribute = 'hostname';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('vendor');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hostname')->searchable()->copyable(),
                TextColumn::make('vendor.name')->label('Vendor')->searchable(),
                TextColumn::make('vendor.status')->label('Vendor status')->badge()->color(fn ($state) => VendorActions::statusColor($state)),
                TextColumn::make('source')->badge(),
                TextColumn::make('provisioning_status')->label('Provisioning')->badge()->sortable(),
                IconColumn::make('is_verified')->label('Live')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_verified')->label('Live'),
                SelectFilter::make('provisioning_status')->options(['manual_pending' => 'Awaiting DNS', 'active' => 'Active', 'suspended' => 'Suspended', 'rejected' => 'Rejected']),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([VendorActions::activateDomain(), VendorActions::unverifyDomain()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStoreDomains::route('/')];
    }
}
