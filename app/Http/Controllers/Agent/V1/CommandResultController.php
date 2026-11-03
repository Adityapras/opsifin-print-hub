<?php

namespace App\Http\Controllers\Agent\V1;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Services\AgentCommandService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\V1\CommandResultRequest;
use Illuminate\Http\Response;

final class CommandResultController extends Controller
{
    public function __invoke(
        CommandResultRequest $request,
        string $commandId,
        AgentCommandService $service,
    ): Response {
        /** @var Agent $agent */
        $agent = $request->attributes->get('agent');

        $service->recordResult(
            $agent,
            $commandId,
            $request->validated('status'),
            $request->validated('message'),
            $request->file('attachment'),
        );

        return response()->noContent();
    }
}
