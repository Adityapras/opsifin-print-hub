<?php

namespace App\Filament\Resources\Printers\Pages;

use App\Filament\Resources\Printers\PrinterResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Resources\Pages\ListRecords;

final class ListPrinters extends ListRecords
{
    use HasTenantSubNavigation;

    protected static string $resource = PrinterResource::class;
}
