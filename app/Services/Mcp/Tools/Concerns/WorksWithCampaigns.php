<?php

namespace App\Services\Mcp\Tools\Concerns;

use App\Enums\Broadcast\ContentType;
use App\Models\Broadcast;
use App\Models\User;
use App\Services\Mcp\Tools\ToolException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The parts every campaign tool needs: find one, and describe it in a shape a
 * model can read without knowing how BroadcastResource nests things.
 *
 * Scoping mirrors BroadcastController::findForTenant(): a campaign carries its
 * tenant, and that is the whole gate — campaigns are a workspace concern, not
 * a per-connection one, and the dashboard lists them the same way.
 */
trait WorksWithCampaigns
{
    protected function findCampaign(mixed $id, User $user): Broadcast
    {
        $campaign = Broadcast::with(['connection', 'creator'])
            ->where('tenant_id', $user->tenant_id)
            ->find((int) $id);

        if (! $campaign) {
            throw new ToolException("There is no campaign with id {$id} in this workspace. Call list_campaigns to see what there is.");
        }

        return $campaign;
    }

    /**
     * The service refuses a state transition with a ValidationException whose
     * message is already a sentence ("Only a paused campaign can be resumed.")
     * — exactly what the model needs, so it is passed through as-is.
     */
    protected function transition(callable $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            throw new ToolException(
                'The campaign cannot do that right now.',
                array_values(array_unique(array_merge(...array_values($e->errors())))),
            );
        }
    }

    /** @return array<string, mixed> */
    protected function describeCampaign(Broadcast $campaign): array
    {
        $total = (int) $campaign->total_recipients;
        $sent = (int) $campaign->sent_count;
        $failed = (int) $campaign->failed_count;
        $skipped = (int) $campaign->skipped_count;
        $connection = $campaign->getRelationValue('connection');

        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            // `inbox` = one message sent into threads an agent selected in the
            // inbox, not a campaign someone composed — worth telling apart.
            'source' => ($campaign->source ?? \App\Enums\Broadcast\Source::Campaign)->value,
            'connection' => $connection ? [
                'name' => $connection->name,
                'channel' => $connection->channel instanceof \BackedEnum ? $connection->channel->value : (string) $connection->channel,
            ] : null,
            'created_by' => $campaign->getRelationValue('creator')?->name,
            'content' => $this->contentSummary($campaign),
            'rate_per_minute' => (int) $campaign->rate_per_minute,
            'recipients' => [
                'total' => $total,
                'sent' => $sent,
                'failed' => $failed,
                'skipped' => $skipped,
                // Same arithmetic as BroadcastResource, so the model and the
                // dashboard never disagree about the same moment.
                'pending' => max(0, $total - $sent - $failed - $skipped),
            ],
            'error' => $campaign->error,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'started_at' => $campaign->started_at?->toIso8601String(),
            'finished_at' => $campaign->finished_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }

    /**
     * What the campaign says, without the Meta components array — that is a
     * send-time structure, long and full of tokens, and the model only needs
     * to know which template or which words went out.
     *
     * @return array<string, mixed>
     */
    private function contentSummary(Broadcast $campaign): array
    {
        $payload = (array) $campaign->payload;

        return array_filter(match ($campaign->content_type) {
            ContentType::Template => [
                'type' => 'template',
                'template_name' => $payload['template_name'] ?? null,
                'language' => $payload['language'] ?? null,
            ],
            ContentType::Media => [
                'type' => 'media',
                'media_type' => $payload['media_type'] ?? null,
                'media_url' => $payload['media_url'] ?? null,
                'caption' => isset($payload['caption']) ? Str::limit((string) $payload['caption'], 500) : null,
            ],
            ContentType::Email => [
                'type' => 'email',
                'subject' => $payload['subject'] ?? null,
                'body' => isset($payload['body']) ? Str::limit(strip_tags((string) $payload['body']), 500) : null,
            ],
            default => [
                'type' => $campaign->content_type->value,
                'body' => isset($payload['body']) ? Str::limit((string) $payload['body'], 500) : null,
            ],
        }, fn ($value) => $value !== null);
    }
}
