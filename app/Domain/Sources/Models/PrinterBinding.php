<?php

namespace App\Domain\Sources\Models;

use App\Domain\Fleet\Models\Printer;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $source_connection_id
 * @property string $printer_id
 * @property string $source_printer_name
 * @property string $source_identifier
 * @property int $printer_version
 * @property bool $is_active
 * @property int $status_sync_attempts
 * @property string|null $last_synced_printer_status
 * @property SourceConnection $sourceConnection
 * @property Printer $printer
 */
class PrinterBinding extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $attributes = [
        'printer_version' => 1,
        'is_active' => false,
    ];

    protected $fillable = [
        'source_connection_id',
        'printer_id',
        'source_printer_name',
        'source_identifier',
        'printer_version',
        'is_active',
        'next_pull_at',
        'last_pulled_at',
        'last_error',
        'last_synced_printer_status',
        'status_sync_attempts',
        'next_status_sync_at',
        'last_status_synced_at',
        'last_status_sync_error',
    ];

    protected static function booted(): void
    {
        static::updating(function (PrinterBinding $binding): void {
            if ($binding->isDirty('source_connection_id')) {
                // Source adalah batas idempotensi dan tujuan callback. Mengubahnya
                // pada binding lama dapat mengirim hasil invoice ke Opsifin yang salah.
                throw new LogicException('A printer binding cannot be moved to another Opsifin connection.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'printer_version' => 'integer',
            'is_active' => 'boolean',
            'next_pull_at' => 'immutable_datetime',
            'last_pulled_at' => 'immutable_datetime',
            'status_sync_attempts' => 'integer',
            'next_status_sync_at' => 'immutable_datetime',
            'last_status_synced_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SourceConnection, $this> */
    public function sourceConnection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class);
    }

    /** @return BelongsTo<Printer, $this> */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }
}
