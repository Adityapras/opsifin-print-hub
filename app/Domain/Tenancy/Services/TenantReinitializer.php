<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Services\LegacyOpsifinClient;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Repositories\TenantResetRepository;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

final class TenantReinitializer
{
    public function __construct(
        private readonly TenantResetRepository $repository,
        private readonly LegacyOpsifinClient $client,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, int> */
    public function run(Tenant $tenant, User $actor, string $reason): array
    {
        $previousTenantId = $this->context->id();
        $tenantId = (string) $tenant->getKey();

        if ($previousTenantId !== null && $previousTenantId !== $tenantId) {
            throw new LogicException('The active tenant context does not match the tenant being reinitialized.');
        }

        // Action Filament berjalan melalui request Livewire terpisah. Pasang konteks secara
        // eksplisit agar relasi tenant dan audit log tidak bergantung pada lifecycle middleware UI.
        $this->context->set($tenantId);

        try {
            return $this->runWithinTenantContext($tenant, $actor, $reason);
        } finally {
            if ($previousTenantId === null) {
                $this->context->clear();
            } else {
                $this->context->set($previousTenantId);
            }
        }
    }

    /** @return array<string, int> */
    private function runWithinTenantContext(Tenant $tenant, User $actor, string $reason): array
    {
        $blockers = $this->repository->blockers($tenant->getKey());

        if ($blockers !== []) {
            $summary = collect($blockers)
                ->map(fn (int $count, string $name): string => str($name)->replace('_', ' ')->title().": {$count}")
                ->implode(', ');

            throw new RuntimeException("Tenant reset is blocked. Resolve these operations first: {$summary}.");
        }

        $connections = $tenant->sourceConnections()->get();

        foreach ($connections as $connection) {
            if ($connection->identity_status !== SourceIdentityStatus::Verified) {
                throw new RuntimeException("Source Connection {$connection->name} must pass identity verification before remote cleanup.");
            }
        }

        // Suspend dulu agar scheduler dan Agent API berhenti sebelum remote/local data berubah.
        $tenant->forceFill(['status' => 'suspended'])->save();

        foreach ($connections as $connection) {
            $response = $this->client->resetPrinterConfiguration(
                $connection,
                $tenant->integration_id,
                $reason,
            );

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Remote cleanup failed for {$connection->name} with HTTP {$response->status()}. The tenant remains suspended and local data was not deleted.",
                );
            }
        }

        $counts = $this->repository->counts($tenant->getKey());
        $documents = $this->repository->documentFiles($tenant->getKey());
        $this->repository->clear($tenant->getKey());

        foreach ($documents as $document) {
            Storage::disk($document['disk'])->delete($document['path']);
        }

        $this->audit->record($actor, 'tenant.reinitialized', $tenant, [], [
            'reason' => $reason,
            'deleted_counts' => $counts,
            'remote_connections_cleaned' => $connections->count(),
            'tenant_status' => 'suspended',
            'integration_id_preserved' => true,
        ]);

        return $counts;
    }
}
