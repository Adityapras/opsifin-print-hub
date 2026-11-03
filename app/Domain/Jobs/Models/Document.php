<?php

namespace App\Domain\Jobs\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $disk
 * @property string $path
 * @property string $sha256
 * @property int $pages
 * @property int $size_bytes
 * @property string|null $source_url
 */
class Document extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'disk',
        'path',
        'sha256',
        'pages',
        'size_bytes',
        'source_url',
        'fetched_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'pages' => 'integer',
            'size_bytes' => 'integer',
            'fetched_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PrintJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }
}
