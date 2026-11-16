<?php

namespace App\Domain\Platform\Models;

use App\Domain\Fleet\Models\AgentUpdateDeployment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $version
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property string $install_policy
 * @property string|null $release_notes
 * @property string $status
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $retired_at
 */
class AgentRelease extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    public const INSTALL_MANUAL = 'manual';

    public const INSTALL_AUTOMATIC = 'automatic';

    public const INSTALL_POLICIES = [self::INSTALL_MANUAL, self::INSTALL_AUTOMATIC];

    protected $fillable = [
        'version',
        'disk',
        'path',
        'original_name',
        'size_bytes',
        'sha256',
        'install_policy',
        'release_notes',
        'status',
        'created_by',
        'published_by',
        'published_at',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'published_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @return HasMany<AgentReleaseEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AgentReleaseEvent::class);
    }

    /** @return HasMany<AgentUpdateDeployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(AgentUpdateDeployment::class);
    }
}
