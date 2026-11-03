<?php

namespace App\Domain\Audit\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property string $subject_id
 * @property array<string, mixed>|null $before_data
 * @property array<string, mixed>|null $after_data
 * @property CarbonImmutable $occurred_at
 */
class AuditLog extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'before_data',
        'after_data',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'before_data' => 'array',
            'after_data' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
