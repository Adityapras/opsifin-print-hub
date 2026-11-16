<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Resources\Pages\CreateRecord;

final class CreateSite extends CreateRecord
{
    use HasTenantSubNavigation;

    protected static string $resource = SiteResource::class;
}
