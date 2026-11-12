<?php

namespace App\Domain\Platform\Services;

interface AgentPackageInspector
{
    public function inspect(string $absolutePath, bool $verifyAuthenticode = true): AgentPackageInspection;
}
