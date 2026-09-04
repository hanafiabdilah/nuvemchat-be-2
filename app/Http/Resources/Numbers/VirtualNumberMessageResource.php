<?php

namespace App\Http\Resources\Numbers;

use Illuminate\Http\Resources\Json\JsonResource;

class VirtualNumberMessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'sender' => $this->sender,
            'body' => $this->body,
            // The OTP API Way extracted. Null is normal — plenty of SMS carry
            // no code at all, and the full text is right beside it.
            'code' => $this->code,
            'received_at' => $this->received_at?->toISOString(),
        ];
    }
}
