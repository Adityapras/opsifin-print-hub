<?php

namespace App\Domain\Jobs\Jobs;

use App\Domain\Jobs\Models\PrintJobRetryRequest;
use App\Domain\Jobs\Repositories\ManualJobRetryRepository;
use App\Domain\Jobs\Services\ManualJobRetryService;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessRefetchRetry implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $requestId) {}

    public function uniqueId(): string
    {
        return $this->requestId;
    }

    public function handle(TenantContext $context, ManualJobRetryService $retries): void
    {
        $request = PrintJobRetryRequest::withoutGlobalScopes()->find($this->requestId);

        if (! $request instanceof PrintJobRetryRequest) {
            return;
        }

        $context->set($request->tenant_id);

        try {
            $scopedRequest = PrintJobRetryRequest::query()->find($this->requestId);

            if ($scopedRequest instanceof PrintJobRetryRequest) {
                $retries->processRefetchRequest($scopedRequest);
            }
        } finally {
            // Queue worker bersifat long-lived; konteks tenant tidak boleh bocor ke job berikutnya.
            $context->clear();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $request = PrintJobRetryRequest::withoutGlobalScopes()->find($this->requestId);

        if (! $request instanceof PrintJobRetryRequest) {
            return;
        }

        $context = app(TenantContext::class);
        $context->set($request->tenant_id);

        try {
            app(ManualJobRetryRepository::class)->markFailed(
                $request,
                $exception?->getMessage() ?? 'The background refetch worker stopped unexpectedly.',
            );
        } finally {
            $context->clear();
        }
    }
}
