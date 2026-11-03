<?php

namespace App\Domain\Sources\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $base_url
 * @property string $username
 * @property string $password_enc
 * @property int $api_version
 * @property int $pull_interval_s
 * @property int $connect_timeout_s
 * @property int $timeout_s
 * @property bool $is_active
 * @property CarbonImmutable|null $last_ok_at
 */
class SourceConnection extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $attributes = [
        'api_version' => 2,
        'pull_interval_s' => 30,
        'active_from' => '05:00:00',
        'active_until' => '22:00:00',
        'connect_timeout_s' => 5,
        'timeout_s' => 30,
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'base_url',
        'username',
        'password_enc',
        'api_version',
        'pull_interval_s',
        'active_from',
        'active_until',
        'connect_timeout_s',
        'timeout_s',
        'is_active',
        'last_ok_at',
        'last_error',
    ];

    protected $hidden = ['password_enc'];

    protected function casts(): array
    {
        return [
            'password_enc' => 'encrypted',
            'api_version' => 'integer',
            'pull_interval_s' => 'integer',
            'connect_timeout_s' => 'integer',
            'timeout_s' => 'integer',
            'is_active' => 'boolean',
            'last_ok_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PrinterBinding, $this> */
    public function bindings(): HasMany
    {
        return $this->hasMany(PrinterBinding::class);
    }
}
