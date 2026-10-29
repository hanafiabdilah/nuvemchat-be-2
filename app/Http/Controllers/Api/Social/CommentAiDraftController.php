<?php

namespace App\Http\Controllers\Api\Social;

use App\Events\ConnectionUpdated;
use App\Exceptions\Billing\CreditExhaustedException;
use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Api\Social\Concerns\ResolvesCommentAccount;
use App\Http\Controllers\Controller;
use App\Models\AiHubAgent;
use App\Models\Connection;
use App\Services\Social\CommentReplyDraft;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * "Responder com IA" on a comment, for Instagram, TikTok and YouTube alike.
 *
 * The draft is returned to the reply box only — somebody reads it, edits it
 * and posts it. See CommentReplyDraft.
 */
class CommentAiDraftController extends Controller
{
    use ResolvesCommentAccount;

    public function draft(Request $request, string $platform, string $accountId, string $commentId, CommentReplyDraft $drafts)
    {
        $account = $this->commentAccount($request, $platform, $accountId);

        $data = $request->validate([
            'text' => ['present', 'nullable', 'string', 'max:10000'],
            'author' => ['nullable', 'string', 'max:200'],
            'post' => ['nullable', 'array'],
            'post.caption' => ['nullable', 'string', 'max:10000'],
            'post.image_url' => ['nullable', 'string', 'max:4096'],
            'thread' => ['nullable', 'array', 'max:50'],
            'thread.*.author' => ['nullable', 'string', 'max:200'],
            'thread.*.text' => ['nullable', 'string', 'max:10000'],
            'thread.*.from_account' => ['nullable', 'boolean'],
        ]);

        if (! CommentReplyDraft::agentFor($account)) {
            return response()->json([
                'message' => 'Escolha o agente de IA que escreve as respostas desta conta.',
                'code' => 'ai_agent_not_linked',
                'can_link' => $request->user()->can('connections.update'),
            ], 422);
        }

        try {
            $draft = $drafts->draft($account, $platform, [
                'text' => (string) ($data['text'] ?? ''),
                'author' => $data['author'] ?? null,
                'post' => $data['post'] ?? [],
                'thread' => array_values($data['thread'] ?? []),
            ]);
        } catch (CreditExhaustedException $th) {
            return response()->json([
                'message' => 'Your credit balance is empty. Top it up to keep using AI.',
                'code' => 'credit_exhausted',
                'balance_cents' => $th->balanceCents,
            ], 402);
        } catch (UpstreamServiceException $th) {
            return $th->toResponse();
        } catch (\Throwable $th) {
            Log::warning('CommentAiDraft: failed to draft a reply', [
                'platform' => $platform,
                'account_id' => $account->id,
                'comment_id' => $commentId,
                'exception' => $th::class,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível gerar a resposta agora. Escreva a resposta ou tente novamente em instantes.',
                'code' => 'ai_draft_failed',
            ], 502);
        }

        return response()->json(['data' => ['draft' => $draft]]);
    }

    /** The agent linked for drafting on the account, and whether the caller may change it. */
    public function agent(Request $request, string $platform, string $accountId)
    {
        $account = $this->commentAccount($request, $platform, $accountId);
        $agent = CommentReplyDraft::agentFor($account);

        return response()->json(['data' => [
            'agent' => $agent ? ['id' => $agent->id, 'name' => $agent->name] : null,
            'can_link' => $request->user()->can('connections.update'),
        ]]);
    }

    /**
     * Pick the agent that drafts on the account. On Instagram and TikTok it is
     * the connection's own drafting agent — the one "Responder com IA" in the
     * direct messages uses too — so changing it is a connection setting, and
     * needs the permission that edits connections.
     */
    public function linkAgent(Request $request, string $platform, string $accountId)
    {
        $account = $this->commentAccount($request, $platform, $accountId);

        abort_unless($request->user()->can('connections.update'), 403, 'You may not change this account\'s AI agent.');

        $data = $request->validate([
            'agent_id' => ['nullable', 'integer'],
        ]);

        $agent = null;

        if (! empty($data['agent_id'])) {
            $agent = AiHubAgent::where('id', $data['agent_id'])
                ->whereHas('aiHubTenant', fn ($q) => $q->where('tenant_id', $request->user()->tenant_id))
                ->firstOrFail();
        }

        $account->update(['ai_suggest_agent_id' => $agent?->id]);

        if ($account instanceof Connection) {
            broadcast(new ConnectionUpdated($account->load('aiSuggestAgent')));
        }

        return response()->json(['data' => [
            'agent' => $agent ? ['id' => $agent->id, 'name' => $agent->name] : null,
            'can_link' => true,
        ]]);
    }
}
