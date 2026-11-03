<?php

namespace App\Domain\Sources\Services;

use App\Domain\Sources\Models\SourceConnection;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

final class SourceConnectionTestService
{
    public function __construct(private readonly LegacyOpsifinClient $client) {}

    public function test(SourceConnection $connection): SourceConnectionTestResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client->sendPrinterStatus($connection, [
                'PrinterName' => '__PRINT_HUB_CONNECTION_TEST__',
                'Identifier' => '__PRINT_HUB__',
                'PrinterStatus' => 'ONLINE',
            ]);
            $durationMs = $this->durationMs($startedAt);

            $result = match (true) {
                $response->successful() => new SourceConnectionTestResult(
                    true,
                    'OK',
                    "Connected in {$durationMs} ms.",
                    $response->status(),
                    $durationMs,
                ),
                in_array($response->status(), [401, 403], true) => new SourceConnectionTestResult(
                    false,
                    'AUTH_FAILED',
                    'The REST username or password was rejected by Opsifin.',
                    $response->status(),
                    $durationMs,
                ),
                $response->status() === 404 => new SourceConnectionTestResult(
                    false,
                    'ENDPOINT_NOT_FOUND',
                    'The PrinterStatus endpoint was not found. Check the Base URL.',
                    404,
                    $durationMs,
                ),
                default => new SourceConnectionTestResult(
                    false,
                    'HTTP_ERROR',
                    "Opsifin responded with HTTP {$response->status()}.",
                    $response->status(),
                    $durationMs,
                ),
            };
        } catch (ConnectionException) {
            $durationMs = $this->durationMs($startedAt);
            $result = new SourceConnectionTestResult(
                false,
                'CONNECTION_FAILED',
                'Opsifin could not be reached before the timeout. Check the network, DNS, and TLS.',
                null,
                $durationMs,
            );
        } catch (RuntimeException) {
            $durationMs = $this->durationMs($startedAt);
            $result = new SourceConnectionTestResult(
                false,
                'INVALID_URL',
                'The Base URL is invalid. Use a complete HTTP or HTTPS URL.',
                null,
                $durationMs,
            );
        } catch (Throwable) {
            $durationMs = $this->durationMs($startedAt);
            $result = new SourceConnectionTestResult(
                false,
                'UNEXPECTED_ERROR',
                'The connection test failed due to an internal error.',
                null,
                $durationMs,
            );
        }

        $connection->update([
            'last_ok_at' => $result->successful ? now() : $connection->last_ok_at,
            'last_error' => $result->successful ? null : $result->message,
        ]);

        return $result;
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
