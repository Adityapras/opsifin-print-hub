<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\AgentUpdateDeployment;

final class AgentUpdateCommandPayload
{
    public function __construct(private readonly AgentUpdatePackageUrl $packageUrl) {}

    /**
     * @return array{version: string, url: string, sha256: string, install_policy: string, release_notes?: string}
     */
    public function build(AgentUpdateDeployment $deployment): array
    {
        $deployment->loadMissing('release');
        $release = $deployment->release;

        $arguments = [
            'version' => $release->version,
            'url' => $this->packageUrl->temporary($deployment),
            'sha256' => $release->sha256,
            'install_policy' => $deployment->install_policy,
        ];

        if (filled($release->release_notes)) {
            $arguments['release_notes'] = $release->release_notes;
        }

        return $arguments;
    }
}
