<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAgentVersionIsSupported
{
    public function handle(Request $request, Closure $next): Response
    {
        $version = $request->header('X-Agent-Version');

        if (! is_string($version) || $version === '') {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The X-Agent-Version header is required.',
                ],
            ], 422);
        }

        $minimumVersion = config('print_hub.min_agent_version');

        if (version_compare($version, $minimumVersion, '<')) {
            return response()->json([
                'error' => [
                    'code' => 'agent_outdated',
                    'message' => "The minimum Agent version is {$minimumVersion}.",
                ],
            ], 426);
        }

        return $next($request);
    }
}
