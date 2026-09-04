<?php

namespace App\Models;

use App\Enums\Gallery\AssetType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * One file in a tenant's media library.
 *
 * The row is the asset — there is no soft delete. A gallery that keeps deleted
 * bytes on disk is a gallery whose usage figure is a lie, and the figure is the
 * thing the customer is paying against.
 */
class GalleryAsset extends Model
{
    protected $fillable = [
        'tenant_id',
        'uuid',
        'public_filename',
        'uploaded_by_user_id',
        'name',
        'path',
        'mime_type',
        'type',
        'size_bytes',
        'checksum',
        'last_used_at',
        'meta',
    ];

    protected $casts = [
        'type' => AssetType::class,
        'size_bytes' => 'integer',
        'last_used_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * The path is an internal storage location and the checksum is how the
     * upload path recognises a duplicate; neither is anyone's business outside
     * this server, and a field serialized into JSON is published whether or not
     * a screen renders it.
     */
    protected $hidden = ['path', 'checksum'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * The URL a channel fetches the bytes from, and the one the browser renders.
     *
     * Signed with no expiry, which is the whole difference between this and
     * message media. A message's URL is deliberately mortal — it dies with the
     * file the retention sweep will delete. A gallery asset has no such date:
     * it lives until the tenant deletes it, and every message ever sent with it
     * points here, so a link that expired on a schedule would turn old bubbles
     * into broken images for no reason anyone could see.
     *
     * The filename is part of the signed URL on purpose. `OutboundMedia` reads
     * the extension off the last path segment to work out the MIME type, and
     * WhatsApp shows that segment as the document's name — a URL ending in a
     * bare uuid arrives as an untyped, unnamed file.
     */
    public function publicUrl(): string
    {
        return URL::signedRoute('gallery.file', [
            'uuid' => $this->uuid,
            'filename' => $this->public_filename,
        ]);
    }

    /** Bytes as a human figure, for notifications and logs. */
    public static function formatBytes(int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        $value = $bytes / (1024 ** $power);

        return ($power === 0 ? (string) (int) $value : number_format($value, $precision, ',', '.'))
            . ' ' . $units[$power];
    }
}
