<?php

namespace App\Filament\Resources\Tenants\Concerns;

use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Resources\Tenants\TenantResource;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Illuminate\Database\Eloquent\Model;

trait HasTenantSubNavigation
{
    use HasTenantWorkspaceGuide;

    abstract public function getParentRecord(): ?Model;

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::End;
    }

    /** @return array<NavigationItem> */
    public function getSubNavigation(): array
    {
        $tenant = $this->getParentRecord();

        return $tenant instanceof Tenant
            ? TenantResource::getTenantSubNavigation($tenant)
            : [];
    }
}
