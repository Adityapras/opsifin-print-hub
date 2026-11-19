<?php

namespace App\Domain\Jobs\Services;

use App\Domain\Fleet\Models\Printer;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Jobs\ProcessRefetchRetry;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobRetryRequest;
use App\Domain\Jobs\Repositories\ManualJobRetryRepository;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Services\SourceDocumentFetcher;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ManualJobRetryService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SourceDocumentFetcher $documentFetcher,
        private readonly ManualJobRetryRepository $repository,
    ) {}

    public function retryPrinting(
        PrintJob $job,
        User $actor,
        string $reason,
        string $destinationBindingId,
    ): PrintJob {
        $this->authorize($job, $actor);
        $this->assertReason($reason);
        $job->loadMissing(['document', 'sourceConnection', 'binding.sourceConnection', 'binding.printer', 'printer']);
        $this->assertRetryable($job, $destinationBindingId);

        if ($job->document === null || ! Storage::disk($job->document->disk)->exists($job->document->path)) {
            throw new DomainException('The stored PDF is unavailable. Use Refetch and retry instead.');
        }

        return $this->repository->requeue(
            $job,
            $actor,
            trim($reason),
            'stored_pdf',
            $destinationBindingId,
        );
    }

    public function requestRefetchAndRetry(
        PrintJob $job,
        User $actor,
        string $reason,
        string $destinationBindingId,
    ): PrintJobRetryRequest {
        $this->authorize($job, $actor);
        $this->assertReason($reason);
        $job->loadMissing(['sourceConnection', 'binding.sourceConnection', 'binding.printer', 'printer']);
        $this->assertRetryable($job, $destinationBindingId);

        $request = $this->repository->scheduleRefetch(
            $job,
            $actor,
            trim($reason),
            $destinationBindingId,
        );

        try {
            ProcessRefetchRetry::dispatch((string) $request->getKey());
        } catch (Throwable $exception) {
            $this->repository->markFailed($request, 'The background worker could not be reached.');

            throw new DomainException(
                'The background refetch request could not be queued. Please try again.',
                previous: $exception,
            );
        }

        return $request;
    }

    public function processRefetchRequest(PrintJobRetryRequest $request): void
    {
        if (in_array($request->status, ['succeeded', 'failed'], true)) {
            return;
        }

        $request = $this->repository->markProcessing($request);
        $request->loadMissing(['job', 'requestedBy']);
        $job = $request->getRelationValue('job');
        $actor = $request->getRelationValue('requestedBy');

        try {
            if (! $job instanceof PrintJob || ! $actor instanceof User) {
                throw new DomainException('The print job or requesting user is no longer available.');
            }

            $destinationBindingId = $request->destination_binding_id ?? $job->binding_id;

            if ($destinationBindingId === null) {
                throw new DomainException('A destination printer binding is required for retry.');
            }

            $this->refetchAndRetry(
                $job,
                $actor,
                $request->reason,
                $destinationBindingId,
                $request,
            );
        } catch (Throwable $exception) {
            $this->repository->markFailed($request, $exception->getMessage());

            throw $exception;
        }
    }

    public function refetchAndRetry(
        PrintJob $job,
        User $actor,
        string $reason,
        string $destinationBindingId,
        ?PrintJobRetryRequest $retryRequest = null,
    ): PrintJob {
        $this->authorize($job, $actor);
        $this->assertReason($reason);
        $job->loadMissing(['document', 'sourceConnection', 'binding.sourceConnection', 'binding.printer', 'printer']);
        $this->assertRetryable($job, $destinationBindingId);

        $link = (string) ($job->source_payload['Link'] ?? '');

        if ($link === '') {
            throw new DomainException('The source payload does not contain a PDF link.');
        }

        try {
            $document = $this->documentFetcher->fetch($this->sourceConnection($job), $link);
        } catch (Throwable $exception) {
            throw new DomainException(
                'The refreshed PDF could not be downloaded or validated: '.$exception->getMessage(),
                previous: $exception,
            );
        }
        $path = sprintf('tenants/%s/documents/%s.pdf', $job->tenant_id, Str::uuid7());

        if (! Storage::disk('local')->put($path, $document['contents'])) {
            throw new DomainException('The refreshed PDF could not be saved.');
        }

        unset($document['contents']);

        try {
            return $this->repository->requeue(
                $job,
                $actor,
                trim($reason),
                'refetched_pdf',
                $destinationBindingId,
                $document + [
                    'disk' => 'local',
                    'path' => $path,
                ],
                $retryRequest,
            );
        } catch (Throwable $exception) {
            // File baru belum menjadi bukti cetak bila transaksi gagal disimpan.
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    private function authorize(PrintJob $job, User $actor): void
    {
        $tenant = $this->context->tenant();

        if (
            ! $tenant instanceof Tenant
            || $job->tenant_id !== $tenant->getKey()
            || ! $actor->hasTenantRole($tenant, ['tenant_admin', 'operator'])
        ) {
            throw new DomainException('You are not allowed to retry this print job.');
        }
    }

    private function assertReason(string $reason): void
    {
        $length = mb_strlen(trim($reason));

        if ($length < 3 || $length > 500) {
            throw new DomainException('A retry reason between 3 and 500 characters is required.');
        }
    }

    private function assertRetryable(PrintJob $job, string $destinationBindingId): void
    {
        if ($job->state !== PrintJobState::Failed) {
            throw new DomainException('Only failed print jobs can be retried.');
        }

        if ($job->lease_agent_id !== null || $job->lease_until !== null) {
            throw new DomainException('The print job still has an active Agent lease.');
        }

        $sourceConnectionId = $job->source_connection_id ?? $job->binding?->source_connection_id;

        if ($sourceConnectionId === null) {
            throw new DomainException('The original Opsifin connection is no longer available.');
        }

        $destination = PrinterBinding::query()
            ->with(['sourceConnection', 'printer'])
            ->find($destinationBindingId);

        if (! $destination instanceof PrinterBinding || $destination->tenant_id !== $job->tenant_id) {
            throw new DomainException('The selected destination binding is not available for this tenant.');
        }

        if ($destination->source_connection_id !== $sourceConnectionId) {
            throw new DomainException('The destination binding must use the same Opsifin connection as the original job.');
        }

        if ($destination->is_active === false) {
            throw new DomainException('The selected destination binding must be active before retrying.');
        }

        if (
            $destination->sourceConnection->is_active === false
            || $destination->sourceConnection->identity_status !== SourceIdentityStatus::Verified
        ) {
            throw new DomainException('The destination Opsifin connection must be active and verified before retrying.');
        }

        $destinationPrinter = $destination->getRelationValue('printer');

        if (! $destinationPrinter instanceof Printer || $destinationPrinter->is_active === false) {
            throw new DomainException('The printer on the selected destination binding must be active before retrying.');
        }
    }

    private function sourceConnection(PrintJob $job): SourceConnection
    {
        $connection = $job->sourceConnection ?? $job->binding?->sourceConnection;

        if (! $connection instanceof SourceConnection) {
            throw new DomainException('The original Opsifin connection is no longer available.');
        }

        return $connection;
    }
}
