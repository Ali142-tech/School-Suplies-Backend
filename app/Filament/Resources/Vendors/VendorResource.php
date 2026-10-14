<?php

namespace App\Filament\Resources\Vendors;

use App\Filament\Resources\Vendors\Pages\ListVendors;
use App\Filament\Resources\Vendors\Pages\ViewVendor;
use App\Filament\Resources\Vendors\RelationManagers\DomainsRelationManager;
use App\Filament\Support\VendorActions;
use App\Models\Vendor;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Vendors & applications';

    protected static ?string $recordTitleAttribute = 'name';

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
        return parent::getEloquentQuery()->with(['owner', 'category'])->withCount('products');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Application')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('status')->badge()->color(fn ($state) => VendorActions::statusColor($state)),
                TextEntry::make('slug')->placeholder('Not assigned'),
                TextEntry::make('requested_slug')->placeholder('-'),
                TextEntry::make('email'),
                TextEntry::make('phone')->placeholder('-'),
                TextEntry::make('category.name')->placeholder('-'),
                TextEntry::make('owner.name')->label('Owner'),
                TextEntry::make('owner.email')->label('Owner email'),
                TextEntry::make('business_address')->columnSpanFull()->placeholder('-'),
                TextEntry::make('description')->columnSpanFull()->placeholder('-'),
            ])->columnSpanFull(),
            Section::make('Review')->columns(3)->schema([
                TextEntry::make('reviewed_at')->dateTime()->placeholder('-'),
                TextEntry::make('approved_at')->dateTime()->placeholder('-'),
                TextEntry::make('rejected_at')->dateTime()->placeholder('-'),
                TextEntry::make('rejection_reason')->columnSpanFull()->placeholder('-'),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state) => VendorActions::statusColor($state))->sortable(),
                TextColumn::make('slug')->searchable()->placeholder('-'),
                TextColumn::make('owner.email')->label('Owner')->searchable(),
                TextColumn::make('products_count')->label('Products')->sortable(),
                TextColumn::make('created_at')->label('Applied')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['pending' => 'Pending', 'active' => 'Active', 'rejected' => 'Rejected', 'suspended' => 'Suspended']),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                VendorActions::approve(),
                VendorActions::reject(),
                VendorActions::suspend(),
                VendorActions::reactivate(),
            ]);
    }

    public static function getRelations(): array
    {
        return [DomainsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
            'view' => ViewVendor::route('/{record}'),
        ];
    }
}
