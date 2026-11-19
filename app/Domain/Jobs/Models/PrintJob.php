<?php

namespace App\Domain\Jobs\Models;

use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $printer_id
 * @property string|null $document_id
 * @property string|null $binding_id
 * @property string|null $source_connection_id
 * @property string|null $dedupe_key
 * @property string|null $lease_agent_id
 * @property array<string, mixed> $source_payload
 * @property PrintJobState $state
 * @property int $attempt_count
 * @property int $run_number
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $printed_at
 * @property CarbonImmutable|null $reported_at
 * @property Document|null $document
 * @property Printer $printer
 * @property SourceConnection|null $sourceConnection
 * @property PrintJobRetryRequest|null $latestRetryRequest
 */
class PrintJob extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $attributes = [
        'run_number' => 1,
    ];

    protected $fillable = [
        'printer_id',
        'document_id',
        'binding_id',
        'source_connection_id',
        'dedupe_key',
        'inv_no',
        'item_type',
        'version_inv',
        'is_hardcopy',
        'customer_id',
        'customer_name',
        'branch_id',
        'implant_code',
        'failure_code',
        'source_payload',
        'state',
        'attempt_count',
        'run_number',
        'lease_agent_id',
        'lease_until',
        'available_at',
        'received_at',
        'printed_at',
        'reported_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => PrintJobState::class,
            'version_inv' => 'integer',
            'is_hardcopy' => 'boolean',
            'source_payload' => 'array',
            'attempt_count' => 'integer',
            'run_number' => 'integer',
            'lease_until' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'printed_at' => 'immutable_datetime',
            'reported_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Printer, $this> */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<PrinterBinding, $this> */
    public function binding(): BelongsTo
    {
        return $this->belongsTo(PrinterBinding::class, 'binding_id');
    }

    /** @return BelongsTo<SourceConnection, $this> */
    public function sourceConnection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class);
    }

    /** @return BelongsTo<Agent, $this> */
    public function leaseAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'lease_agent_id');
    }

    /** @return HasMany<PrintJobAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PrintJobAttempt::class, 'job_id');
    }

    /** @return HasMany<PrintJobEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PrintJobEvent::class, 'job_id')->oldest('recorded_at');
    }

    /** @return HasOne<CallbackOutbox, $this> */
    public function outbox(): HasOne
    {
        return $this->hasOne(CallbackOutbox::class, 'job_id')->ofMany('job_run', 'max');
    }

    /** @return HasMany<CallbackOutbox, $this> */
    public function outboxes(): HasMany
    {
        return $this->hasMany(CallbackOutbox::class, 'job_id');
    }

    /** @return HasMany<PrintJobRetryRequest, $this> */
    public function retryRequests(): HasMany
    {
        return $this->hasMany(PrintJobRetryRequest::class, 'job_id');
    }

    /** @return HasOne<PrintJobRetryRequest, $this> */
    public function latestRetryRequest(): HasOne
    {
        return $this->hasOne(PrintJobRetryRequest::class, 'job_id')->latestOfMany();
    }
}
