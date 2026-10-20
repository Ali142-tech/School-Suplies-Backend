<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Support\VendorActions;
use Filament\Resources\Pages\ViewRecord;

class ViewVendor extends ViewRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VendorActions::approve(),
            VendorActions::reject(),
            VendorActions::suspend(),
            VendorActions::reactivate(),
            VendorActions::assignPlatformSubdomain(),
            VendorActions::addCustomDomain(),
        ];
    }
}
