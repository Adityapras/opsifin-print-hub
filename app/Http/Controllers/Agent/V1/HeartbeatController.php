<?php

namespace App\Http\Controllers\Agent\V1;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Services\AgentCommandService;
use App\Domain\Fleet\Services\AgentConfigBuilder;
use App\Domain\Fleet\Services\AgentUpdateLifecycleService;
use App\Domain\Fleet\Services\HeartbeatService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\V1\HeartbeatRequest;
use Illuminate\Http\JsonResponse;

final class HeartbeatController extends Controller
{
    public function __invoke(
        HeartbeatRequest $request,
        HeartbeatService $heartbeat,
        AgentConfigBuilder $configBuilder,
        AgentCommandService $commands,
        AgentUpdateLifecycleService $updates,
    ): JsonResponse {
        /** @var Agent $authenticatedAgent */
        $authenticatedAgent = $request->attributes->get('agent');
        $agent = $heartbeat->record($authenticatedAgent, $request->validated());
        $updates->observeHeartbeat($agent, $request->validated('update'));

        $response = [
            'server_time' => now()->utc()->toIso8601String(),
            'config_version' => $agent->config_version,
            'commands' => $commands->forHeartbeat($agent),
        ];

        if ((int) $request->input('config_version', 0) !== $agent->config_version) {
            $response['config'] = $configBuilder->build($agent);
        }

        return response()->json($response);
    }
}
