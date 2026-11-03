<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\Tenant;

final class TenantContext
{
    private ?string $tenantId = null;

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenantId === null ? null : Tenant::query()->find($this->tenantId);
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }
}
