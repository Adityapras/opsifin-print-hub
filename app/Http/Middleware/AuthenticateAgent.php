<?php

namespace App\Http\Middleware;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateAgent
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $credentials = $this->parseCredentials($request->bearerToken());

        if ($credentials === null) {
            return $this->unauthorized();
        }

        [$agentId, $deviceSecret] = $credentials;
        $agent = Agent::withoutGlobalScopes()->find($agentId);
        $tenantIsActive = $agent !== null
            && Tenant::query()
                ->whereKey($agent->tenant_id)
                ->where('status', 'active')
                ->exists();

        if (
            $agent === null
            || ! $tenantIsActive
            || $agent->revoked_at !== null
            || ! hash_equals($agent->secret_hash, hash('sha256', $deviceSecret))
        ) {
            return $this->unauthorized();
        }

        $this->tenantContext->set($agent->tenant_id);
        $request->attributes->set('agent', $agent);

        try {
            return $next($request);
        } finally {
            // Worker HTTP yang long-lived tidak boleh membawa konteks request sebelumnya.
            $this->tenantContext->clear();
        }
    }

    /**
     * @return array{string, string}|null
     */
    private function parseCredentials(?string $token): ?array
    {
        if ($token === null || ! str_contains($token, '.')) {
            return null;
        }

        [$agentId, $deviceSecret] = explode('.', $token, 2);

        if ($agentId === '' || $deviceSecret === '') {
            return null;
        }

        return [$agentId, $deviceSecret];
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'invalid_token',
                'message' => 'The Agent token is invalid or has been revoked.',
            ],
        ], 401);
    }
}
