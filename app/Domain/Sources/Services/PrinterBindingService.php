<?php

namespace App\Domain\Sources\Services;

use App\Domain\Fleet\Models\Printer;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Exceptions\PrinterBindingSyncException;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

final class PrinterBindingService
{
    private const SYNC_FIELDS = [
        'source_connection_id',
        'printer_id',
        'source_printer_name',
        'source_identifier',
        'printer_version',
    ];

    public function __construct(private readonly LegacyOpsifinClient $client) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): PrinterBinding
    {
        $binding = new PrinterBinding;
        $binding->fill($attributes);

        $connection = SourceConnection::query()->findOrFail($binding->source_connection_id);
        $this->ensureIdentityVerified($connection);
        $printer = Printer::query()->findOrFail($binding->printer_id);
        $alias = $this->printerAlias($printer);

        // Endpoint lama memasukkan printer baru ketika nama lama sama dengan nama baru.
        $this->sync($connection, $this->payload(
            $binding,
            $binding->source_printer_name,
            $alias,
            $alias,
        ));

        $binding->save();
        $binding->refresh();
        $binding->load(['sourceConnection', 'printer']);

        return $binding;
    }

    /** @param array<string, mixed> $attributes */
    public function update(PrinterBinding $binding, array $attributes): PrinterBinding
    {
        $binding->loadMissing('printer');
        $oldPrinterName = $binding->source_printer_name;
        $oldPrinterAlias = $this->printerAlias($binding->printer);

        $binding->fill($attributes);

        if ($binding->is_active || $binding->isDirty(self::SYNC_FIELDS)) {
            $connection = SourceConnection::query()->findOrFail($binding->source_connection_id);
            $this->ensureIdentityVerified($connection);
        }

        if ($binding->isDirty(self::SYNC_FIELDS)) {
            $connection = SourceConnection::query()->findOrFail($binding->source_connection_id);
            $printer = Printer::query()->findOrFail($binding->printer_id);

            $this->sync($connection, $this->payload(
                $binding,
                $oldPrinterName,
                $this->printerAlias($printer),
                $oldPrinterAlias,
            ));
        }

        $binding->save();
        $binding->refresh();
        $binding->load(['sourceConnection', 'printer']);

        return $binding;
    }

    public function syncExisting(PrinterBinding $binding): void
    {
        $binding->loadMissing(['sourceConnection', 'printer']);
        $this->ensureIdentityVerified($binding->sourceConnection);
        $alias = $this->printerAlias($binding->printer);

        // Nama lama disamakan agar endpoint legacy melakukan upsert tanpa membuat log rename palsu.
        $this->sync($binding->sourceConnection, $this->payload(
            $binding,
            $binding->source_printer_name,
            $alias,
            $alias,
        ));
    }

    /**
     * @return array<string, int|string>
     */
    private function payload(
        PrinterBinding $binding,
        string $oldPrinterName,
        string $printerAlias,
        string $oldPrinterAlias,
    ): array {
        return [
            'PrinterName' => $binding->source_printer_name,
            'PrinterNameOld' => $oldPrinterName,
            'PrinterAlias' => $printerAlias,
            'PrinterAliasOld' => $oldPrinterAlias,
            'PrinterVersion' => $binding->printer_version,
            'Identifier' => $binding->source_identifier,
        ];
    }

    /** @param array<string, int|string> $payload */
    private function sync(SourceConnection $connection, array $payload): void
    {
        try {
            $response = $this->client->syncPrinterBinding($connection, $payload);
            $this->ensureSuccessful($response);
        } catch (PrinterBindingSyncException $exception) {
            $this->markConnectionFailed($connection, $exception->getMessage());

            throw $exception;
        } catch (ConnectionException) {
            $message = 'Opsifin could not be reached. Check the network, DNS, and TLS before saving the binding.';
            $this->markConnectionFailed($connection, $message);

            throw new PrinterBindingSyncException($message);
        } catch (Throwable) {
            $message = 'The printer binding could not be synchronized due to an unexpected error.';
            $this->markConnectionFailed($connection, $message);

            throw new PrinterBindingSyncException($message);
        }

        $connection->update([
            'last_ok_at' => now(),
            'last_error' => null,
        ]);
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = match (true) {
            in_array($response->status(), [401, 403], true) => 'Opsifin rejected the REST username or password.',
            $response->status() === 404 => 'The printerName endpoint was not found. Check the connection Base URL.',
            default => "Opsifin rejected the printer binding with HTTP {$response->status()}.",
        };

        throw new PrinterBindingSyncException($message);
    }

    private function markConnectionFailed(SourceConnection $connection, string $message): void
    {
        $connection->update(['last_error' => $message]);
    }

    private function printerAlias(Printer $printer): string
    {
        return filled($printer->alias) ? (string) $printer->alias : $printer->windows_name;
    }

    private function ensureIdentityVerified(SourceConnection $connection): void
    {
        if ($connection->identity_status !== SourceIdentityStatus::Verified) {
            throw new PrinterBindingSyncException(
                'Verify the Opsifin Integration Identity before creating, activating, or synchronizing a binding.',
            );
        }
    }
}
