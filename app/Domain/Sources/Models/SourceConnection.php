<?php

namespace App\Domain\Sources\Models;

use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property SourceIdentityStatus $identity_status
 * @property string|null $remote_integration_id
 * @property CarbonImmutable|null $identity_verified_at
 * @property CarbonImmutable|null $last_ok_at
 * @property Tenant $tenant
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
        'is_active' => false,
        'identity_status' => SourceIdentityStatus::Pending->value,
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

    protected static function booted(): void
    {
        static::creating(function (SourceConnection $connection): void {
            // Connection baru tidak boleh operasional sebelum remote membuktikan
            // Integration ID tenant melalui endpoint identity.
            $connection->forceFill([
                'is_active' => false,
                'identity_status' => SourceIdentityStatus::Pending,
            ]);
        });

        static::updating(function (SourceConnection $connection): void {
            if ($connection->isDirty(['base_url', 'username', 'password_enc'])) {
                // Perubahan tujuan atau credential membatalkan bukti sebelumnya karena
                // request berikutnya bisa mengarah ke instance client yang berbeda.
                $connection->forceFill([
                    'is_active' => false,
                    'identity_status' => SourceIdentityStatus::Pending,
                    'remote_integration_id' => null,
                    'identity_verified_at' => null,
                ]);
            }

            if ($connection->identity_status !== SourceIdentityStatus::Verified) {
                $connection->is_active = false;
            }
        });

        static::updated(function (SourceConnection $connection): void {
            if ($connection->wasChanged(['base_url', 'username', 'password_enc'])) {
                // Badge binding harus mengikuti guard operasional agar operator tidak
                // mengira routing lama masih aktif setelah tujuan connection berubah.
                $connection->bindings()->update(['is_active' => false]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'password_enc' => 'encrypted',
            'api_version' => 'integer',
            'pull_interval_s' => 'integer',
            'connect_timeout_s' => 'integer',
            'timeout_s' => 'integer',
            'is_active' => 'boolean',
            'identity_status' => SourceIdentityStatus::class,
            'identity_verified_at' => 'immutable_datetime',
            'last_ok_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<PrinterBinding, $this> */
    public function bindings(): HasMany
    {
        return $this->hasMany(PrinterBinding::class);
    }
}
