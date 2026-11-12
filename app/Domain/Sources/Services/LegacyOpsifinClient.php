<?php

namespace App\Domain\Sources\Services;

use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class LegacyOpsifinClient
{
    /** @return array<int, array<string, mixed>> */
    public function pull(SourceConnection $connection, PrinterBinding $binding): array
    {
        $this->assertVerified($connection);

        $response = $this->authenticated($connection)
            ->post($this->url($connection->base_url, 'getData'), [
                'VersionAPI' => $connection->api_version,
                'PrinterName' => $binding->source_printer_name,
                'PrinterVersion' => $binding->printer_version,
                'Identifier' => $binding->source_identifier,
            ])
            ->throw();

        $payload = $response->json();

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new RuntimeException('The getData response must be a list of jobs.');
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    public function downloadPdf(SourceConnection $connection, string $url): string
    {
        $this->assertVerified($connection);
        $this->assertHttpUrl($url);

        $response = Http::accept('application/pdf')
            ->connectTimeout($connection->connect_timeout_s)
            ->timeout($connection->timeout_s)
            ->get($url)
            ->throw();

        $contents = $response->body();

        if (strlen($contents) > config('print_hub.max_pdf_bytes')) {
            throw new RuntimeException('The PDF exceeds the size limit.');
        }

        return $contents;
    }

    /** @param array<string, mixed> $payload */
    public function sendCallback(SourceConnection $connection, string $endpoint, array $payload): Response
    {
        $this->assertVerified($connection);
        $this->assertHttpUrl($endpoint);

        return $this->authenticated($connection)->post($endpoint, $payload);
    }

    /** @param array<string, string> $payload */
    public function sendPrinterStatus(SourceConnection $connection, array $payload): Response
    {
        $this->assertVerified($connection);

        return $this->authenticated($connection)
            ->post($this->url($connection->base_url, 'PrinterStatus'), $payload);
    }

    /** @param array<string, int|string> $payload */
    public function syncPrinterBinding(SourceConnection $connection, array $payload): Response
    {
        $this->assertVerified($connection);

        return $this->authenticated($connection)
            ->post($this->url($connection->base_url, 'printerName'), $payload);
    }

    public function identity(SourceConnection $connection): Response
    {
        return $this->authenticated($connection)
            ->get($this->url($connection->base_url, 'identity'));
    }

    private function authenticated(SourceConnection $connection): PendingRequest
    {
        return Http::withBasicAuth($connection->username, $connection->password_enc)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($connection->connect_timeout_s)
            ->timeout($connection->timeout_s);
    }

    private function url(string $baseUrl, string $path): string
    {
        $this->assertHttpUrl($baseUrl);

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function assertHttpUrl(string $url): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('The source URL is invalid.');
        }
    }

    private function assertVerified(SourceConnection $connection): void
    {
        if ($connection->identity_status !== SourceIdentityStatus::Verified) {
            throw new RuntimeException('The Opsifin connection identity has not been verified.');
        }
    }
}
