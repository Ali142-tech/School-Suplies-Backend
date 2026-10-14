<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\VendorResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListVendors extends ListRecords
{
    protected static string $resource = VendorResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending applications')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'active' => Tab::make('Active')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'active')),
            'rejected' => Tab::make('Rejected')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
            'suspended' => Tab::make('Suspended')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'suspended')),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
