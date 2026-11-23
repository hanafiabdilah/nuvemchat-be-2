<?php

namespace App\Http\Controllers\Api\Incident;

use App\Enums\Incident\MatchMode;
use App\Enums\Incident\ReplyMode;
use App\Exceptions\PublicApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Incident\IncidentTemplateResource;
use App\Models\AiHubAgent;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Services\Incident\IncidentMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ready incidents ("Instabilidade de rede", "Manutenção programada"): the
 * words and keywords written calmly, so the day it happens is one click.
 */
class IncidentTemplateController extends Controller
{
    public function index(Request $request)
    {
        return IncidentTemplateResource::collection(
            IncidentTemplate::where('tenant_id', $request->user()->tenant_id)->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        if (IncidentTemplate::where('tenant_id', $tenantId)->count() >= IncidentTemplate::MAX_PER_TENANT) {
            throw new PublicApiException(__('incidents.errors.template_limit'), 'incident_template_limit');
        }

        $template = IncidentTemplate::create(['tenant_id' => $tenantId] + $this->validated($request));

        return (new IncidentTemplateResource($template))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): IncidentTemplateResource
    {
        $template = IncidentTemplate::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $template->update($this->validated($request));

        return new IncidentTemplateResource($template);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        IncidentTemplate::where('tenant_id', $request->user()->tenant_id)->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:120'],
            'match_mode' => ['required', Rule::enum(MatchMode::class)],
            'keywords' => ['sometimes', 'nullable', 'array', 'max:'.Incident::MAX_KEYWORDS],
            'keywords.*' => ['string', 'min:'.IncidentMatcher::MIN_KEYWORD_LENGTH, 'max:'.IncidentMatcher::MAX_KEYWORD_LENGTH],
            'exclude_keywords' => ['sometimes', 'nullable', 'array', 'max:'.Incident::MAX_KEYWORDS],
            'exclude_keywords.*' => ['string', 'min:'.IncidentMatcher::MIN_KEYWORD_LENGTH, 'max:'.IncidentMatcher::MAX_KEYWORD_LENGTH],
            'reply_mode' => ['sometimes', Rule::enum(ReplyMode::class)],
            'ai_hub_agent_id' => ['sometimes', 'nullable', 'integer'],
            'ai_brief' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'message' => ['required', 'string', 'max:4096'],
            'media_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'media_type' => ['sometimes', 'nullable', Rule::in(['image', 'video', 'document', 'audio'])],
            'resolution_message' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'duration_minutes' => ['sometimes', 'integer', 'min:'.Incident::MIN_DURATION_MINUTES, 'max:'.Incident::MAX_DURATION_MINUTES],
        ]);

        $keywords = IncidentMatcher::cleanKeywords($data['keywords'] ?? []);
        $exclude = IncidentMatcher::cleanKeywords($data['exclude_keywords'] ?? []);

        foreach ([...$keywords, ...$exclude] as $keyword) {
            if (! IncidentMatcher::isUsableKeyword($keyword)) {
                throw ValidationException::withMessages(['keywords' => __('incidents.errors.keyword_too_short', ['keyword' => $keyword])]);
            }
        }

        if ($data['match_mode'] === MatchMode::Keywords->value && $keywords === []) {
            throw ValidationException::withMessages(['keywords' => __('incidents.errors.keywords_required')]);
        }

        $mediaUrl = trim((string) ($data['media_url'] ?? '')) ?: null;

        // A template remembers the agent, but only one of this workspace.
        $agentId = isset($data['ai_hub_agent_id']) ? (int) $data['ai_hub_agent_id'] : null;

        if ($agentId !== null && ! AiHubAgent::whereKey($agentId)
            ->whereHas('aiHubTenant', fn ($query) => $query->where('tenant_id', $request->user()->tenant_id))
            ->exists()) {
            $agentId = null;
        }

        return [
            'name' => trim($data['name']),
            'title' => trim($data['title']),
            'match_mode' => $data['match_mode'],
            'reply_mode' => $data['reply_mode'] ?? ReplyMode::Fixed->value,
            'ai_hub_agent_id' => $agentId,
            'ai_brief' => trim((string) ($data['ai_brief'] ?? '')) ?: null,
            'keywords' => $keywords ?: null,
            'exclude_keywords' => $exclude ?: null,
            'message' => trim($data['message']),
            'media_url' => $mediaUrl,
            'media_type' => $mediaUrl ? ($data['media_type'] ?? 'image') : null,
            'resolution_message' => trim((string) ($data['resolution_message'] ?? '')) ?: null,
            'duration_minutes' => (int) ($data['duration_minutes'] ?? Incident::DEFAULT_DURATION_MINUTES),
        ];
    }
}
