<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class FlowNodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data;

        // Regenerate attachment URL if expired or will expire soon (within 1 day)
        if (is_array($data) && isset($data['attachment_file']) && !empty($data['attachment_file'])) {
            $shouldRegenerate = false;

            // Check if URL is missing or will expire soon
            if (empty($data['attachment_url'])) {
                $shouldRegenerate = true;
            } elseif ($this->isUrlExpiringSoon($data['attachment_url'])) {
                $shouldRegenerate = true;
            }

            // Regenerate URL if needed
            if ($shouldRegenerate && Storage::disk('local')->exists($data['attachment_file'])) {
                $data['attachment_url'] = Storage::disk('local')->temporaryUrl(
                    $data['attachment_file'],
                    now()->addDays(7)
                );
            }
        }

        return [
            'id' => $this->id,
            'flow_id' => $this->flow_id,
            'type' => $this->type->value,
            'data' => $data,
            'position_x' => $this->position_x,
            'position_y' => $this->position_y,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Check if URL is expiring soon (within 1 day)
     */
    private function isUrlExpiringSoon(string $url): bool
    {
        // Parse URL to get expires parameter
        $urlParts = parse_url($url);

        if (!isset($urlParts['query'])) {
            return true; // No query string, consider expired
        }

        parse_str($urlParts['query'], $queryParams);

        if (!isset($queryParams['expires'])) {
            return true; // No expires param, consider expired
        }

        $expiresAt = $queryParams['expires'];

        // Check if expired or will expire within 1 day
        return $expiresAt < (now()->addDay()->timestamp);
    }
}
