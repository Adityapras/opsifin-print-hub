<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Resources\Pages\ListRecords;

final class ListAuditLogs extends ListRecords
{
    use HasTenantSubNavigation;

    protected static string $resource = AuditLogResource::class;
}
