<?php

namespace App\Domain\Platform\Services;

final readonly class AgentPackageInspection
{
    public function __construct(
        public string $productVersion,
    ) {}
}
