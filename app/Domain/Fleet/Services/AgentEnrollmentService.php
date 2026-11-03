<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Repositories\AgentEnrollmentRepository;

final class AgentEnrollmentService
{
    public function __construct(private readonly AgentEnrollmentRepository $repository) {}

    /**
     * @param  array{hostname: string, os: string, agent_version: string, machine_id: string}  $attributes
     * @return array{agent: Agent, device_secret: string}|null
     */
    public function enroll(string $pairingCode, array $attributes): ?array
    {
        $deviceSecret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $agent = $this->repository->consumePairingCode(
            hash('sha256', strtoupper($pairingCode)),
            hash('sha256', $deviceSecret),
            $attributes,
        );

        if ($agent === null) {
            return null;
        }

        return [
            'agent' => $agent,
            'device_secret' => $deviceSecret,
        ];
    }
}
