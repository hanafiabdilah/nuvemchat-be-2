<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A YouTube channel connected to a workspace, from the YouTube page.
 *
 * Not a Connection — see the migration. Access is the workspace's: anyone
 * holding the YouTube permissions reaches every channel the workspace
 * connected, because there is no inbox here for per-connection access to
 * protect.
 */
class YoutubeAccount extends Model
{
    public const STATUS_ACTIVE = 'active';

    /** Google refused the refresh token; only connecting again can fix it. */
    public const STATUS_RECONNECT = 'reconnect_required';

    protected $fillable = [
        'tenant_id',
        'connected_by',
        'ai_suggest_agent_id',
        'channel_id',
        'title',
        'handle',
        'thumbnail_url',
        'uploads_playlist_id',
        'credentials',
        'status',
        'last_error',
        'connected_at',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'connected_at' => 'datetime',
    ];

    /** Tokens never leave the server, not even to a resource that forgot to omit them. */
    protected $hidden = ['credentials'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /**
     * The agent that drafts replies to this channel's comments — what
     * `connections.ai_suggest_agent_id` is for an Instagram or TikTok account.
     */
    public function aiSuggestAgent(): BelongsTo
    {
        return $this->belongsTo(AiHubAgent::class, 'ai_suggest_agent_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(YoutubePost::class);
    }

    public function needsReconnect(): bool
    {
        return $this->status === self::STATUS_RECONNECT;
    }

    public function watchUrl(): string
    {
        return 'https://www.youtube.com/channel/'.$this->channel_id;
    }
}
