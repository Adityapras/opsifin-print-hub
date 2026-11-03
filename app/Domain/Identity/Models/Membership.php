<?php

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $user_id
 * @property string $role
 * @property array<int, string>|null $site_ids
 */
class Membership extends Pivot
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'memberships';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'role',
        'site_ids',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'site_ids' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
