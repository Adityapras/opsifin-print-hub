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
 * @property string $channel
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property string $signature_type
 * @property string|null $signature_value
 * @property string|null $signing_key_id
 * @property string|null $manifest_path
 * @property string|null $signature_subject
 * @property string|null $release_notes
 * @property string $status
 * @property CarbonImmutable $signature_verified_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $retired_at
 */
class AgentRelease extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    public const SIGNATURE_AUTHENTICODE = 'authenticode';

    public const SIGNATURE_OPSIGO_INTERNAL = 'opsigo-rsa-sha256-v1';

    protected $fillable = [
        'version',
        'channel',
        'disk',
        'path',
        'original_name',
        'size_bytes',
        'sha256',
        'signature_type',
        'signature_value',
        'signing_key_id',
        'manifest_path',
        'signature_subject',
        'signature_verified_at',
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
            'signature_verified_at' => 'immutable_datetime',
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
