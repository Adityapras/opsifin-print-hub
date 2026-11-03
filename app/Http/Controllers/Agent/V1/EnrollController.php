<?php

namespace App\Http\Controllers\Agent\V1;

use App\Domain\Fleet\Services\AgentConfigBuilder;
use App\Domain\Fleet\Services\AgentEnrollmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\V1\EnrollRequest;
use Illuminate\Http\JsonResponse;

final class EnrollController extends Controller
{
    public function __invoke(
        EnrollRequest $request,
        AgentEnrollmentService $enrollment,
        AgentConfigBuilder $configBuilder,
    ): JsonResponse {
        $validated = $request->validated();
        $result = $enrollment->enroll($validated['pairing_code'], $validated);

        if ($result === null) {
            return response()->json([
                'error' => [
                    'code' => 'pairing_code_invalid',
                    'message' => 'The pairing code is invalid, already used, or expired.',
                ],
            ], 422);
        }

        $agent = $result['agent'];

        return response()->json([
            'agent_id' => $agent->getKey(),
            'device_secret' => $result['device_secret'],
            'tenant_id' => $agent->tenant_id,
            'site_id' => $agent->site_id,
            'config' => $configBuilder->build($agent),
        ], 201);
    }
}
