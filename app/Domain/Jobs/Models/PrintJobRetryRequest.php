<?php

namespace App\Domain\Jobs\Models;

use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $job_id
 * @property string|null $destination_binding_id
 * @property int|null $requested_by
 * @property string $mode
 * @property string $status
 * @property string $reason
 * @property string|null $error_message
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property PrintJob $job
 * @property PrinterBinding|null $destinationBinding
 * @property User|null $requestedBy
 */
final class PrintJobRetryRequest extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ACTIVE_STATUSES = ['pending', 'processing'];

    protected $fillable = [
        'job_id',
        'destination_binding_id',
        'requested_by',
        'mode',
        'status',
        'reason',
        'error_message',
        'requested_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_by' => 'integer',
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PrintJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'job_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<PrinterBinding, $this> */
    public function destinationBinding(): BelongsTo
    {
        return $this->belongsTo(PrinterBinding::class, 'destination_binding_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
