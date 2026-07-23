<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Global platform key-value settings. Values are encrypted at rest and cached
 * to keep reads cheap on hot paths (e.g. the API Way channel).
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'encrypted',
    ];

    /** Never expose the (decrypted) value in JSON. */
    protected $hidden = ['value'];

    private static function cacheKey(string $key): string
    {
        return "setting:{$key}";
    }

    /**
     * Read a setting value (decrypted), with caching.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever(
            self::cacheKey($key),
            fn () => static::query()->where('key', $key)->first()?->value,
        );

        return $value ?? $default;
    }

    /**
     * Upsert a setting value and bust its cache.
     */
    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($key));
    }
}
