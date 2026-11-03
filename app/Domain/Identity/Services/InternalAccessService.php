<?php

namespace App\Domain\Identity\Services;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Collection;

final class InternalAccessService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<int, array{tenant_id?: string|null, role?: string|null}>  $assignments
     */
    public function sync(User $user, array $assignments): void
    {
        $normalized = collect($assignments)
            ->filter(fn (array $assignment): bool => filled($assignment['tenant_id'] ?? null))
            ->mapWithKeys(fn (array $assignment): array => [
                (string) $assignment['tenant_id'] => in_array($assignment['role'] ?? null, ['tenant_admin', 'operator', 'viewer'], true)
                    ? (string) $assignment['role']
                    : 'viewer',
            ]);

        $tenants = $this->validTenants($normalized);
        $previousTenantId = $this->tenantContext->id();

        try {
            $user->getConnection()->transaction(function () use ($user, $normalized, $tenants): void {
                $user->memberships()->get()->each->delete();

                foreach ($normalized as $tenantId => $role) {
                    if (! $tenants->has($tenantId)) {
                        continue;
                    }

                    $this->tenantContext->set($tenantId);
                    $user->memberships()->create(['role' => $role]);
                }
            });
        } finally {
            if ($previousTenantId === null) {
                $this->tenantContext->clear();
            } else {
                $this->tenantContext->set($previousTenantId);
            }
        }
    }

    /**
     * @param  Collection<string, string>  $assignments
     * @return Collection<string, Tenant>
     */
    private function validTenants(Collection $assignments): Collection
    {
        return Tenant::query()
            ->whereKey($assignments->keys())
            ->get()
            ->keyBy(fn (Tenant $tenant): string => (string) $tenant->getKey());
    }
}
