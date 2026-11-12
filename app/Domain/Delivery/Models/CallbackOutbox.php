<?php

namespace App\Domain\Delivery\Models;

use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $job_id
 * @property int $job_run
 * @property string $source_connection_id
 * @property string $endpoint
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property CarbonImmutable $next_attempt_at
 * @property CarbonImmutable $expires_at
 */
class CallbackOutbox extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'callback_outbox';

    protected $fillable = [
        'job_id',
        'job_run',
        'source_connection_id',
        'endpoint',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'expires_at',
        'last_http_status',
        'last_response',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'job_run' => 'integer',
            'attempts' => 'integer',
            'last_http_status' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PrintJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'job_id');
    }

    /** @return BelongsTo<SourceConnection, $this> */
    public function sourceConnection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class);
    }
}
