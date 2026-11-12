<?php

namespace App\Domain\Platform\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

final class NativeAgentPackageInspector implements AgentPackageInspector
{
    public function inspect(string $absolutePath, bool $verifyAuthenticode = true): AgentPackageInspection
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('The uploaded MSI package is no longer available.');
        }

        $signatureSubject = null;
        if ($verifyAuthenticode) {
            $arguments = ['osslsigncode', 'verify'];
            $caFile = config('print_hub.agent_updates.authenticode_ca_file');
            if (is_string($caFile) && $caFile !== '') {
                $arguments[] = '-CAfile';
                $arguments[] = $caFile;
            }
            $arguments[] = '-in';
            $arguments[] = $absolutePath;

            $signature = new Process($arguments);
            $signature->setTimeout(60);
            $signature->run();
            $signatureOutput = trim($signature->getOutput()."\n".$signature->getErrorOutput());
            if (! $signature->isSuccessful()
                || ! str_contains(strtolower($signatureOutput), 'signature verification: ok')) {
                throw new RuntimeException('Authenticode verification failed. The package was not published. '.$signatureOutput);
            }

            preg_match('/^\s*Subject:\s*(.+)$/mi', $signatureOutput, $subjectMatch);
            $signatureSubject = trim($subjectMatch[1] ?? 'Authenticode signature verified');
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

        return new AgentPackageInspection(
            $productVersion,
            $signatureSubject,
        );
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
