<?php

namespace App\Domain\Jobs\Services;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobAttempt;
use App\Domain\Jobs\Repositories\JobClaimRepository;
use Illuminate\Support\Facades\URL;

final class JobClaimService
{
    public function __construct(private readonly JobClaimRepository $repository) {}

    /**
     * @param  array<int, string>  $printerIds
     * @return array<int, array<string, mixed>>
     */
    public function claim(Agent $agent, array $printerIds): array
    {
        return array_map(function (array $claim): array {
            /** @var PrintJob $job */
            $job = $claim['job'];
            /** @var PrintJobAttempt $attempt */
            $attempt = $claim['attempt'];
            /** @var Document $document */
            $document = $job->document;
            $printer = $job->printer;

            return [
                'job_id' => $job->getKey(),
                'attempt_id' => $attempt->getKey(),
                'printer_id' => $job->printer_id,
                'lease_until' => $job->lease_until->utc()->toIso8601String(),
                'document' => [
                    'url' => URL::temporarySignedRoute(
                        'agent.v1.documents.show',
                        now()->addMinutes(5),
                        ['documentId' => $document->getKey()],
                    ),
                    'sha256' => $document->sha256,
                    'pages' => $document->pages,
                    'size_bytes' => $document->size_bytes,
                ],
                'print' => array_replace(config('print_hub.default_print_settings'), $printer->print_settings ?? []),
            ];
        }, $this->repository->claim($agent, $printerIds));
    }
}
