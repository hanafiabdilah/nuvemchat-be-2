<?php

namespace App\Http\Resources;

use App\Enums\Connection\Channel;
use App\Enums\Message\MessageType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MessageResource extends JsonResource
{
    public bool $withoutAttachmentUrl = false;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_type' => $this->sender_type,
            'message_type' => $this->message_type,
            'body' => $this->body,
            'attachment_url' => $this->when(!$this->withoutAttachmentUrl, fn() =>
                $this->attachment ? Storage::disk('local')->temporaryUrl($this->attachment, Carbon::now()->addMonths(6)) : null
            ),
            'replied_message' => $this->when($this->repliedMessage, fn() => [
                'id' => $this->repliedMessage->id,
                'sender_type' => $this->repliedMessage->sender_type,
                'message_type' => $this->repliedMessage->message_type,
                'body' => $this->repliedMessage->body,
                'attachment_url' => $this->repliedMessage->attachment && !$this->withoutAttachmentUrl
                    ? Storage::disk('local')->temporaryUrl($this->repliedMessage->attachment, Carbon::now()->addMonths(6))
                    : null,
            ]),
            'sent_at' => $this->sent_at,
            'delivery_at' => $this->delivery_at,
            'read_at' => $this->read_at,
            'edited_at' => $this->edited_at,
            'unsend_at' => $this->unsend_at,
            'meta' => $this->getProcessedMeta(),
            'created_at' => $this->created_at->timestamp,
            'updated_at' => $this->updated_at->timestamp,
        ];
    }

    /**
     * Get processed meta based on channel and message type
     */
    private function getProcessedMeta(): ?array
    {
        $channel = $this->conversation->connection->channel ?? null;

        if (!$channel) {
            return null;
        }

        return match($channel) {
            Channel::WhatsappWApi => $this->getWhatsappWApiMeta(),
            Channel::WhatsappOfficial => null, // TODO: implement when needed
            Channel::Instagram => null,        // TODO: implement when needed
            Channel::Telegram => null,         // TODO: implement when needed
            default => null,
        };
    }

    /**
     * Get processed meta for WhatsApp W-API messages
     */
    private function getWhatsappWApiMeta(): ?array
    {
        return match($this->message_type) {
            MessageType::Location => $this->getWhatsappWApiLocationData(),
            default => null,
        };
    }

    /**
     * Extract location data from WhatsApp W-API meta
     */
    private function getWhatsappWApiLocationData(): ?array
    {
        $location = $this->meta['msgContent']['locationMessage'] ?? null;

        if (!$location) {
            return null;
        }

        $latitude = $location['degreesLatitude'] ?? null;
        $longitude = $location['degreesLongitude'] ?? null;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'google_maps_url' => "https://www.google.com/maps?q={$latitude},{$longitude}",
            ],
        ];
    }
}
