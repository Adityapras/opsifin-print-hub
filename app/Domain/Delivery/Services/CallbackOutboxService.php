<?php

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Delivery\Repositories\CallbackOutboxRepository;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Sources\Models\PrinterBinding;

final class CallbackOutboxService
{
    public function __construct(private readonly CallbackOutboxRepository $repository) {}

    public function enqueueForTerminal(PrintJob $job, ?PrinterBinding $binding = null): ?CallbackOutbox
    {
        if (! in_array($job->state, [PrintJobState::Printed, PrintJobState::Skipped, PrintJobState::Failed], true)) {
            return null;
        }

        $binding ??= $job->binding;

        if ($binding === null) {
            return null;
        }

        $connection = $binding->sourceConnection;
        $result = $job->state === PrintJobState::Failed ? 'Failed' : 'Success';
        $payload = [
            'InvNo' => $job->inv_no,
            'ItemType' => $job->item_type,
            'PrintProcess' => '',
            'PrintProcessV2' => '',
        ];

        if ($binding->printer_version === 1 && $job->version_inv === 2) {
            $payload['PrintProcessV2'] = $result;
        } else {
            $payload['PrintProcess'] = $result;
        }

        if ($job->state !== PrintJobState::Skipped) {
            $payload['PrinterName'] = $binding->source_printer_name;
        }

        return $this->repository->enqueue(
            $job,
            $connection,
            rtrim($connection->base_url, '/'),
            $payload,
        );
    }
}
