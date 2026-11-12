<?php

namespace App\Filament\Resources\PrintJobs\Pages;

use App\Filament\Resources\PrintJobs\PrintJobResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Resources\Pages\ListRecords;

final class ListPrintJobs extends ListRecords
{
    use HasTenantSubNavigation;

    protected static string $resource = PrintJobResource::class;
}
