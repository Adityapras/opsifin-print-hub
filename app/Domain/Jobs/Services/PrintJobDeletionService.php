<?php

namespace App\Domain\Jobs\Services;

use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Repositories\PrintJobDeletionRepository;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Storage;

final class PrintJobDeletionService
{
    public function __construct(
        private readonly PrintJobDeletionRepository $repository,
        private readonly TenantContext $context,
    ) {}

    public function blocker(PrintJob $job): ?string
    {
        return $this->repository->blocker($job);
    }

    /**
     * @return array{deleted_counts: array<string, int>, file_cleanup_failures: int}
     */
    public function delete(PrintJob $job, User $actor, string $reason): array
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new DomainException('A deletion reason of at least 10 characters is required.');
        }

        $tenant = $this->context->tenant();

        if (
            ! $tenant instanceof Tenant
            || $tenant->getKey() !== $job->tenant_id
            || ! $actor->hasTenantRole($tenant, ['tenant_admin'])
        ) {
            throw new DomainException('You are not allowed to delete this print job.');
        }

        $result = $this->repository->delete($job, $actor, $reason);

        $fileCleanupFailures = 0;

        foreach ($result['files'] as $file) {
            $disk = Storage::disk($file['disk']);

            if ($disk->exists($file['path']) && ! $disk->delete($file['path'])) {
                // Baris database sudah terhapus secara atomik. File yang gagal dibersihkan
                // dilaporkan agar operator tidak menerima status sukses yang menyesatkan.
                $fileCleanupFailures++;
            }
        }

        return [
            'deleted_counts' => $result['deleted_counts'],
            'file_cleanup_failures' => $fileCleanupFailures,
        ];
    }
}
