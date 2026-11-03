<?php

namespace App\Http\Controllers\Agent\V1;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Jobs\Services\JobClaimService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\V1\ClaimJobsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class ClaimJobsController extends Controller
{
    public function __invoke(ClaimJobsRequest $request, JobClaimService $service): JsonResponse|Response
    {
        /** @var Agent $agent */
        $agent = $request->attributes->get('agent');
        $jobs = $service->claim($agent, $request->validated('printer_ids'));

        if ($jobs === []) {
            return response()->noContent();
        }

        return response()->json(['jobs' => $jobs]);
    }
}
