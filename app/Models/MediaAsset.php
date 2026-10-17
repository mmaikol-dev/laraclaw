<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaAsset extends Model
{
    use HasFactory;

    public const STATUS_UNPOSTED = 'unposted';

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'slug',
        'title',
        'filename',
        'path',
        'platform',
        'status',
        'posted_at',
        'source',
        'size_bytes',
        'external_id',
        'external_url',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    public function scopePosted(Builder $query): void
    {
        $query->where('status', self::STATUS_POSTED);
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    public function scopeUnposted(Builder $query): void
    {
        $query->where('status', self::STATUS_UNPOSTED);
    }
}
