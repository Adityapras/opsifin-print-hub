<?php

namespace App\Domain\Platform\Services;

final readonly class AgentReleaseManifest
{
    public function __construct(
        public string $signatureScheme,
        public string $signingKeyId,
        public string $signature,
    ) {}
}
