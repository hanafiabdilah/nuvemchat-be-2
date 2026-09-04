<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One SMS received on a rented number.
 *
 * `code` is the OTP API Way already extracted from the text. It is stored, not
 * derived on read, because the extraction is theirs and a second parser here
 * would disagree with the one the customer's app actually sent.
 */
class VirtualNumberMessage extends Model
{
    protected $fillable = [
        'virtual_number_id',
        'tenant_id',
        'provider_message_id',
        'sender',
        'body',
        'code',
        'received_at',
        'dedupe_key',
        'meta',
    ];

    protected $casts = [
        'provider_message_id' => 'integer',
        'received_at' => 'datetime',
        'meta' => 'array',
    ];

    public function number()
    {
        return $this->belongsTo(VirtualNumber::class, 'virtual_number_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * What makes two payloads the same message.
     *
     * Always hashed from the content, never from an upstream id — even though
     * one of the three sources has ids. The same SMS reaches us by webhook (no
     * id), by `/numbers/{id}/sms` (no id) and by `/numbers/{id}` (an id), so a
     * key that switched form per source would let the same message in twice
     * under two different keys. The timestamp is part of the hash on purpose:
     * two identical texts minutes apart are two messages.
     */
    public static function dedupeKeyFor(?string $sender, ?string $body, ?string $receivedAt): string
    {
        return hash('sha256', implode('|', [$sender ?? '', $body ?? '', $receivedAt ?? '']));
    }
}
