<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessageLog extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'recipient',
        'type',
        'body',
        'status',
        'error',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a WhatsApp send attempt. Never throws — logging must not break a send.
     */
    public static function record(string $provider, string $recipient, string $type, ?string $body, string $status, ?string $error = null, ?int $userId = null, ?array $meta = null): void
    {
        try {
            static::create([
                'user_id' => $userId,
                'provider' => $provider,
                'recipient' => $recipient,
                'type' => $type,
                'body' => $body,
                'status' => $status,
                'error' => $error,
                'meta' => $meta,
            ]);
        } catch (\Throwable $th) {
            // Swallow: an audit-log failure must never affect the caller.
        }
    }
}
