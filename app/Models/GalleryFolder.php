<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A folder in a tenant's media library.
 *
 * Organisation only: a file's bytes, path and public URL are the same in every
 * folder, so moving one is a column update and never a broken link. Every rule
 * about the tree (depth, names, what deleting does to the contents) lives in
 * GalleryFolderService, not here.
 */
class GalleryFolder extends Model
{
    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'created_by_user_id',
    ];

    /**
     * Integers, always: GalleryFolderService compares parent ids strictly while
     * walking the tree, and a driver that hands one back as a string would make
     * every folder look parentless.
     */
    protected $casts = [
        'tenant_id' => 'integer',
        'parent_id' => 'integer',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assets()
    {
        return $this->hasMany(GalleryAsset::class, 'folder_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
