<?php

namespace App\Domain\Platform\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

final class NativeAgentPackageInspector implements AgentPackageInspector
{
    public function inspect(string $absolutePath): AgentPackageInspection
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('The uploaded MSI package is no longer available.');
        }

        $properties = new Process(['msiinfo', 'export', $absolutePath, 'Property']);
        $properties->setTimeout(30);
        $properties->run();
        if (! $properties->isSuccessful()) {
            throw new RuntimeException('The MSI ProductVersion could not be inspected. '.$properties->getErrorOutput());
        }

        $productVersion = $this->property($properties->getOutput(), 'ProductVersion');
        if ($productVersion === null) {
            throw new RuntimeException('The MSI does not contain a ProductVersion property.');
        }

        return new AgentPackageInspection($productVersion);
    }

    private function property(string $table, string $name): ?string
    {
        foreach (preg_split('/\R/', $table) ?: [] as $line) {
            $columns = explode("\t", $line);
            if ($columns[0] === $name && isset($columns[1])) {
                return trim($columns[1]);
            }
        }

        return null;
    }
}
