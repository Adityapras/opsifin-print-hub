<?php

namespace App\Domain\Sources\Services;

use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\SourceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SourceConnectionTestService
{
    public function __construct(
        private readonly LegacyOpsifinClient $client,
        private readonly SourceAgentConfigInvalidator $config,
    ) {}

    public function test(SourceConnection $connection): SourceConnectionTestResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client->identity($connection);
            $result = $this->resultFromResponse($connection, $response, $startedAt);
        } catch (ConnectionException) {
            $result = $this->failure($startedAt, 'CONNECTION_FAILED', 'Opsifin could not be reached before the timeout. Check the network, DNS, and TLS.');
        } catch (RuntimeException) {
            $result = $this->failure($startedAt, 'INVALID_URL', 'The Base URL is invalid. Use a complete HTTP or HTTPS URL.');
        } catch (Throwable) {
            $result = $this->failure($startedAt, 'UNEXPECTED_ERROR', 'Identity verification failed due to an internal error.');
        }

        $this->persistResult($connection, $result);

        return $result;
    }

    private function resultFromResponse(SourceConnection $connection, Response $response, int $startedAt): SourceConnectionTestResult
    {
        $durationMs = $this->durationMs($startedAt);

        if (in_array($response->status(), [401, 403], true)) {
            return new SourceConnectionTestResult(false, 'AUTH_FAILED', 'The REST username or password was rejected by Opsifin.', $response->status(), $durationMs);
        }

        if ($response->status() === 404) {
            return new SourceConnectionTestResult(false, 'IDENTITY_ENDPOINT_NOT_FOUND', 'The identity endpoint was not found. Update opsifin-main before using this connection.', 404, $durationMs);
        }

        if (! $response->successful()) {
            return new SourceConnectionTestResult(false, 'HTTP_ERROR', "Opsifin responded with HTTP {$response->status()} during identity verification.", $response->status(), $durationMs);
        }

        $remoteIntegrationId = $response->json('integration_id');

        if (! is_string($remoteIntegrationId) || ! Str::isUuid($remoteIntegrationId)) {
            return new SourceConnectionTestResult(false, 'IDENTITY_INVALID_RESPONSE', 'The identity endpoint did not return a valid integration_id.', $response->status(), $durationMs);
        }

        $connection->loadMissing('tenant');
        $matches = hash_equals(strtolower((string) $connection->tenant->integration_id), strtolower($remoteIntegrationId));

        return new SourceConnectionTestResult(
            $matches,
            $matches ? 'IDENTITY_VERIFIED' : 'IDENTITY_MISMATCH',
            $matches
                ? "Identity verified in {$durationMs} ms. This connection belongs to the selected tenant."
                : 'Identity mismatch. This URL belongs to a different tenant and cannot be used.',
            $response->status(),
            $durationMs,
            $remoteIntegrationId,
        );
    }

    private function persistResult(SourceConnection $connection, SourceConnectionTestResult $result): void
    {
        $status = match ($result->code) {
            'IDENTITY_VERIFIED' => SourceIdentityStatus::Verified,
            'IDENTITY_MISMATCH' => SourceIdentityStatus::Mismatch,
            default => SourceIdentityStatus::Unavailable,
        };

        $connection->forceFill([
            'identity_status' => $status,
            'remote_integration_id' => $result->remoteIntegrationId,
            'identity_verified_at' => $result->successful ? now() : null,
            'is_active' => false,
            'last_ok_at' => $result->successful ? now() : $connection->last_ok_at,
            'last_error' => $result->successful ? null : $result->message,
        ])->saveQuietly();

        if (! $result->successful) {
            // Binding ikut dinonaktifkan supaya job lama tidak tetap berjalan setelah
            // tujuan connection terbukti salah atau tidak lagi dapat diverifikasi.
            $connection->bindings()->update(['is_active' => false]);
        }

        // saveQuietly mencegah health check otomatis masuk audit log, tetapi perubahan
        // eligibility source tetap harus memicu pengiriman ulang remote config Agent.
        $this->config->forConnection($connection);
    }

    private function failure(int $startedAt, string $code, string $message): SourceConnectionTestResult
    {
        return new SourceConnectionTestResult(false, $code, $message, null, $this->durationMs($startedAt));
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
