<?php

namespace App\Http\Controllers\Api\Instagram;

use App\Exceptions\InstagramApiException;
use App\Http\Controllers\Api\Instagram\Concerns\ResolvesInstagramConnection;
use App\Http\Controllers\Controller;
use App\Models\CommentDmAutomation;
use App\Models\CommentDmRun;
use App\Models\Connection;
use App\Services\Instagram\CommentDm\CommentDmAutomations;
use App\Services\Instagram\InstagramGraphClientFactory;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The "Comentário → Direct" rules of one Instagram account, and what they did
 * lately. See CommentDmAutomations for how a comment is matched and answered.
 */
class InstagramCommentAutomationController extends Controller
{
    use ResolvesInstagramConnection;

    private const RECENT_RUNS = 50;

    public function __construct(
        private readonly InstagramGraphClientFactory $clients,
    ) {}

    public function index(Request $request, string $connectionId)
    {
        $connection = $this->instagramConnection($request, $connectionId);

        $rules = CommentDmAutomation::where('connection_id', $connection->id)
            ->withCount(['runs as sent_count' => fn ($q) => $q->where('status', CommentDmRun::STATUS_SENT)])
            ->orderBy('id')
            ->get();

        $runs = CommentDmRun::where('connection_id', $connection->id)
            ->with('automation:id,name')
            ->latest('id')
            ->limit(self::RECENT_RUNS)
            ->get();

        return response()->json([
            'data' => $rules->map(fn (CommentDmAutomation $rule) => $this->present($rule))->values(),
            'runs' => $runs->map(fn (CommentDmRun $run) => [
                'id' => $run->id,
                'automation_id' => $run->automation_id,
                'automation_name' => $run->automation?->name,
                'comment_id' => $run->comment_id,
                'media_id' => $run->media_id,
                'commenter_username' => $run->commenter_username,
                'comment_text' => $run->comment_text,
                'status' => $run->status,
                'reason' => $run->reason,
                'error' => $run->error,
                'conversation_id' => $run->conversation_id,
                'created_at' => $run->created_at?->toIso8601String(),
            ])->values(),
            'limits' => ['max' => CommentDmAutomations::MAX_PER_ACCOUNT],
        ]);
    }

    public function store(Request $request, string $connectionId)
    {
        $connection = $this->instagramConnection($request, $connectionId);

        if (CommentDmAutomation::where('connection_id', $connection->id)->count() >= CommentDmAutomations::MAX_PER_ACCOUNT) {
            throw ValidationException::withMessages([
                'name' => 'This account already has the most automations it can have.',
            ]);
        }

        $rule = CommentDmAutomation::create($this->validated($request) + [
            'tenant_id' => $connection->tenant_id,
            'connection_id' => $connection->id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->present($rule->loadCount(['runs as sent_count' => fn ($q) => $q->where('status', CommentDmRun::STATUS_SENT)])),
            'webhook' => $this->subscribe($connection, $rule),
        ], 201);
    }

    public function update(Request $request, string $connectionId, string $automationId)
    {
        $connection = $this->instagramConnection($request, $connectionId);
        $rule = CommentDmAutomation::where('connection_id', $connection->id)->findOrFail($automationId);

        $rule->update($this->validated($request));

        return response()->json([
            'data' => $this->present($rule->loadCount(['runs as sent_count' => fn ($q) => $q->where('status', CommentDmRun::STATUS_SENT)])),
            'webhook' => $this->subscribe($connection, $rule),
        ]);
    }

    public function destroy(Request $request, string $connectionId, string $automationId)
    {
        $connection = $this->instagramConnection($request, $connectionId);

        CommentDmAutomation::where('connection_id', $connection->id)->findOrFail($automationId)->delete();

        return response()->json(['message' => 'Automation deleted.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'media_ids' => ['nullable', 'array', 'max:30'],
            'media_ids.*' => ['string', 'max:64'],
            'match_mode' => ['required', Rule::in([CommentDmAutomation::MATCH_ANY, CommentDmAutomation::MATCH_KEYWORDS])],
            'keywords' => ['nullable', 'array', 'max:30'],
            'keywords.*' => ['nullable', 'string', 'max:60'],
            'public_replies' => ['nullable', 'array', 'max:10'],
            'public_replies.*' => ['nullable', 'string', 'max:300'],
            // Instagram's limit on a direct message.
            'dm_message' => ['required', 'string', 'max:1000'],
        ]);

        $keywords = collect($data['keywords'] ?? [])
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()
            ->unique(fn ($keyword) => mb_strtolower($keyword))
            ->values()
            ->all();

        if ($data['match_mode'] === CommentDmAutomation::MATCH_KEYWORDS && $keywords === []) {
            throw ValidationException::withMessages(['keywords' => 'Add at least one keyword.']);
        }

        if (isset($data['media_ids']) && $data['media_ids'] === []) {
            throw ValidationException::withMessages(['media_ids' => 'Choose at least one post.']);
        }

        return [
            'name' => trim($data['name']),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'media_ids' => isset($data['media_ids']) ? array_values(array_unique(array_map('strval', $data['media_ids']))) : null,
            'match_mode' => $data['match_mode'],
            'keywords' => $data['match_mode'] === CommentDmAutomation::MATCH_KEYWORDS ? $keywords : null,
            'public_replies' => array_values(array_filter(array_map(fn ($reply) => trim((string) $reply), $data['public_replies'] ?? []))) ?: null,
            'dm_message' => trim($data['dm_message']),
        ];
    }

    /**
     * Comments only reach us once the account is subscribed to them. Accounts
     * connected before this feature were not, so a rule switched on asks for
     * it. Not a reason to refuse the save — the rule is kept, and the page
     * says the comments are not arriving yet.
     *
     * @return array{subscribed: bool, message?: string}
     */
    private function subscribe(Connection $connection, CommentDmAutomation $rule): array
    {
        if (! $rule->enabled) {
            return ['subscribed' => true];
        }

        try {
            $this->clients->for($connection)->subscribeWebhooks();

            return ['subscribed' => true];
        } catch (\Throwable $th) {
            return [
                'subscribed' => false,
                'message' => UpstreamError::message(
                    UpstreamProvider::Meta,
                    $th->getMessage(),
                    upstreamCode: $th instanceof InstagramApiException ? $th->metaCode() : null,
                    status: $th instanceof InstagramApiException ? $th->httpStatus() : 502,
                    context: ['operation' => 'instagram.subscribe_comments', 'connection_id' => $connection->id],
                ),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function present(CommentDmAutomation $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'enabled' => $rule->enabled,
            'media_ids' => $rule->media_ids,
            'match_mode' => $rule->match_mode,
            'keywords' => $rule->keywords ?? [],
            'public_replies' => $rule->public_replies ?? [],
            'dm_message' => $rule->dm_message,
            'sent_count' => (int) ($rule->sent_count ?? 0),
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }
}
