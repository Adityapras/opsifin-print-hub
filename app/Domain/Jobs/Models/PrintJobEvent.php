<?php

namespace App\Domain\Jobs\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJobEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'id',
        'job_id',
        'attempt_id',
        'actor_type',
        'actor_id',
        'type',
        'from_state',
        'to_state',
        'occurred_at',
        'recorded_at',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'data' => 'array',
        ];
    }

    /** @return BelongsTo<PrintJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'job_id');
    }

    /** @return BelongsTo<PrintJobAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PrintJobAttempt::class, 'attempt_id');
    }
}
