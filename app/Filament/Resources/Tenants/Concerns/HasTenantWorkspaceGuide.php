<?php

namespace App\Filament\Resources\Tenants\Concerns;

use App\Filament\Widgets\TenantWorkspaceGuide;

trait HasTenantWorkspaceGuide
{
    /** @return array<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [TenantWorkspaceGuide::class];
    }
}
