<?php

namespace App\Domain\Platform\Services;

interface AgentPackageInspector
{
    public function inspect(string $absolutePath): AgentPackageInspection;
}
