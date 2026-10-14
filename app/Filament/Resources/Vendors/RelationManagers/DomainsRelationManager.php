<?php

namespace App\Filament\Resources\Vendors\RelationManagers;

use App\Filament\Support\VendorActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DomainsRelationManager extends RelationManager
{
    protected static string $relationship = 'domains';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('hostname')
            ->columns([
                TextColumn::make('hostname')->copyable(),
                TextColumn::make('source')->badge(),
                TextColumn::make('provisioning_status')->label('Provisioning')->badge(),
                IconColumn::make('is_verified')->label('Live')->boolean(),
                IconColumn::make('is_primary')->label('Primary')->boolean(),
                TextColumn::make('verified_at')->dateTime()->placeholder('-'),
            ])
            ->recordActions([VendorActions::activateDomain(), VendorActions::unverifyDomain()]);
    }
}
