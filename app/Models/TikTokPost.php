<?php

namespace App\Models;

use App\Enums\TikTok\PostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A TikTok post composed in Pingly: a draft, a schedule, one on its way, or
 * the receipt for one that landed.
 */
class TikTokPost extends Model
{
    public const MEDIA_VIDEO = 'video';
    public const MEDIA_PHOTO = 'photo';

    protected $table = 'tiktok_posts';

    protected $fillable = [
        'tenant_id',
        'connection_id',
        'created_by',
        'status',
        'media_type',
        'title',
        'caption',
        'privacy_level',
        'disable_comment',
        'disable_duet',
        'disable_stitch',
        'is_ai_generated',
        'is_brand_organic',
        'is_branded_content',
        'auto_add_music',
        'scheduled_at',
        'published_at',
        'share_id',
        'tiktok_item_id',
        'error',
        'attempts',
    ];

    protected $casts = [
        'status' => PostStatus::class,
        'disable_comment' => 'boolean',
        'disable_duet' => 'boolean',
        'disable_stitch' => 'boolean',
        'is_ai_generated' => 'boolean',
        'is_brand_organic' => 'boolean',
        'is_branded_content' => 'boolean',
        'auto_add_music' => 'boolean',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connection(): BelongsTo
    {
        // `connection` is a reserved Eloquent property; read it through
        // connectionModel(). Same trap as InstagramPost.
        return $this->belongsTo(Connection::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TikTokPostItem::class, 'tiktok_post_id')->orderBy('position');
    }

    public function connectionModel(): ?Connection
    {
        return $this->getRelationValue('connection');
    }

    public function isPhoto(): bool
    {
        return $this->media_type === self::MEDIA_PHOTO;
    }
}
