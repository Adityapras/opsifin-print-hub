<?php

namespace App\Domain\Jobs\Services;

use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Repositories\JobIntakeRepository;
use App\Domain\Sources\Models\PrinterBinding;

final class JobIntakeService
{
    public function __construct(private readonly JobIntakeRepository $repository) {}

    /** @param array<string, mixed> $payload */
    public function duplicate(PrinterBinding $binding, array $payload): ?PrintJob
    {
        return $this->repository->findDuplicate($binding, $payload);
    }

    public function recordDuplicate(PrintJob $job, PrinterBinding $binding): void
    {
        $this->repository->recordDuplicate($job, $binding);
    }

    /** @param array<string, mixed> $payload */
    public function skip(PrinterBinding $binding, array $payload): PrintJob
    {
        return $this->repository->createSkipped($binding, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{disk: string, path: string, sha256: string, pages: int, size_bytes: int, source_url: string}  $document
     */
    public function queue(PrinterBinding $binding, array $payload, array $document): PrintJob
    {
        return $this->repository->createQueued($binding, $payload, $document);
    }

    /** @param array<string, mixed> $payload */
    public function fail(PrinterBinding $binding, array $payload, string $failureCode): PrintJob
    {
        return $this->repository->createFailed($binding, $payload, $failureCode);
    }
}
