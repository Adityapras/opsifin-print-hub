<?php

namespace App\Domain\Sources\Services;

use App\Domain\Jobs\Services\JobIntakeService;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Exceptions\InvalidPdfException;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Repositories\SourcePullRepository;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SourcePullService
{
    public function __construct(
        private readonly LegacyOpsifinClient $client,
        private readonly SourceDocumentFetcher $documentFetcher,
        private readonly JobIntakeService $jobs,
        private readonly SourcePullRepository $repository,
    ) {}

    /** @return array{received: int, queued: int, skipped: int, failed: int, duplicates: int} */
    public function pull(PrinterBinding $binding): array
    {
        $result = ['received' => 0, 'queued' => 0, 'skipped' => 0, 'failed' => 0, 'duplicates' => 0];
        $binding->loadMissing(['sourceConnection', 'printer']);

        if (
            $binding->is_active === false
            || $binding->sourceConnection->is_active === false
            || $binding->sourceConnection->identity_status !== SourceIdentityStatus::Verified
            || $binding->printer->is_active === false
        ) {
            return $result;
        }

        if (! $this->withinActiveHours($binding)) {
            return $result;
        }

        try {
            $items = $this->client->pull($binding->sourceConnection, $binding);
        } catch (Throwable $exception) {
            $this->repository->markFailed($binding, $exception->getMessage());

            throw $exception;
        }

        foreach ($items as $payload) {
            try {
                $this->assertIdentity($payload);
                $result['received']++;

                $duplicate = $this->jobs->duplicate($binding, $payload);

                if ($duplicate !== null) {
                    $this->jobs->recordDuplicate($duplicate, $binding);
                    $result['duplicates']++;

                    continue;
                }

                if (strtoupper((string) ($payload['IsHardCopyInv'] ?? 'Y')) === 'N') {
                    $this->jobs->skip($binding, $payload);
                    $result['skipped']++;

                    continue;
                }

                $outcome = $this->downloadAndQueue($binding, $payload);
                $result[$outcome]++;
            } catch (Throwable) {
                // Satu payload rusak tidak boleh menahan antrean lain dari binding yang sama.
                $result['failed']++;
            }
        }

        $this->repository->markSuccess($binding, $result['failed']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return 'queued'|'failed'
     */
    private function downloadAndQueue(PrinterBinding $binding, array $payload): string
    {
        $link = isset($payload['Link']) ? (string) $payload['Link'] : '';

        if ($link === '') {
            $this->jobs->fail($binding, $payload, 'PDF_DOWNLOAD_FAILED');

            return 'failed';
        }

        try {
            $document = $this->documentFetcher->fetch($binding->sourceConnection, $link);
        } catch (InvalidPdfException) {
            $this->jobs->fail($binding, $payload, 'PDF_INVALID');

            return 'failed';
        } catch (Throwable) {
            $this->jobs->fail($binding, $payload, 'PDF_DOWNLOAD_FAILED');

            return 'failed';
        }

        $path = sprintf('tenants/%s/documents/%s.pdf', $binding->tenant_id, Str::uuid7());

        if (! Storage::disk('local')->put($path, $document['contents'])) {
            throw new RuntimeException('The PDF could not be saved to local storage.');
        }

        try {
            unset($document['contents']);

            $this->jobs->queue($binding, $payload, $document + [
                'disk' => 'local',
                'path' => $path,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return 'queued';
    }

    /** @param array<string, mixed> $payload */
    private function assertIdentity(array $payload): void
    {
        foreach (['InvNo', 'ItemType', 'VersionInv'] as $key) {
            if (! isset($payload[$key]) || $payload[$key] === '') {
                throw new InvalidArgumentException("The source payload is missing {$key}.");
            }
        }
    }

    private function withinActiveHours(PrinterBinding $binding): bool
    {
        $tenant = Tenant::query()->findOrFail($binding->tenant_id);
        $now = CarbonImmutable::now($tenant->timezone)->format('H:i:s');
        $start = (string) $binding->sourceConnection->active_from;
        $end = (string) $binding->sourceConnection->active_until;

        if ($start <= $end) {
            return $now >= $start && $now < $end;
        }

        // Rentang yang melewati tengah malam, misalnya 22:00–05:00.
        return $now >= $start || $now < $end;
    }
}
