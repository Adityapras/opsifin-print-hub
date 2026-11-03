<?php

namespace App\Http\Controllers\Agent\V1;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Jobs\Services\JobEventService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\V1\JobEventsRequest;
use Illuminate\Http\JsonResponse;

final class JobEventsController extends Controller
{
    public function __invoke(
        JobEventsRequest $request,
        string $jobId,
        JobEventService $service,
    ): JsonResponse {
        /** @var Agent $agent */
        $agent = $request->attributes->get('agent');
        $result = $service->record($agent, $jobId, $request->validated('events'));

        if ($result === null) {
            return response()->json([
                'error' => [
                    'code' => 'lease_lost',
                    'message' => 'This Agent no longer holds the job lease.',
                ],
            ], 409);
        }

        return response()->json($result);
    }
}
