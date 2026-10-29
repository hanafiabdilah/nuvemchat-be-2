<?php

namespace App\Services\Social;

use App\Models\AiHubAgent;
use App\Models\Connection;
use App\Models\YoutubeAccount;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use RuntimeException;

/**
 * "Responder com IA" on a comment: the account's AI agent writes a draft reply,
 * and the draft only ever lands in the reply box — somebody reads it, edits it
 * and presses Reply. A comment is public, so nothing here posts anything.
 *
 * The agent is the one already linked for drafting on the account: the same
 * `ai_suggest_agent_id` that drafts direct-message replies on an Instagram or
 * TikTok connection (one voice for the account's DMs and its comments), and a
 * channel's own column on YouTube, which has no connection.
 *
 * The context travels in the request because the person asking is looking at
 * it: the post's caption, the comment, the replies already under it. Reading
 * it back from the network would cost a call per draft — on YouTube, quota —
 * to learn what the screen already shows. The post's picture is the one thing
 * that is not text, and it is only accepted from the network's own image
 * hosts: the hub fetches whatever URL it is handed.
 */
class CommentReplyDraft
{
    /** Image hosts a post picture may come from, per network (suffix match). */
    private const IMAGE_HOSTS = [
        'instagram' => ['cdninstagram.com', 'fbcdn.net'],
        'tiktok' => ['tiktokcdn.com', 'tiktokcdn-us.com', 'tiktokcdn-eu.com', 'tiktokv.com', 'ibyteimg.com', 'byteimg.com', 'muscdn.com'],
        'youtube' => ['ytimg.com', 'ggpht.com', 'googleusercontent.com'],
    ];

    private const NETWORK_LABEL = [
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
    ];

    /** The reply limits the reply endpoints enforce, so a draft always fits. */
    public const MAX_REPLY = [
        'instagram' => 2200,
        'tiktok' => 1200,
        'youtube' => 10000,
    ];

    public function __construct(
        private readonly AiAgentHubTenantService $hub,
    ) {}

    public static function agentFor(Connection|YoutubeAccount $account): ?AiHubAgent
    {
        return $account->aiSuggestAgent;
    }

    /**
     * @param  array{text: string, author?: ?string, post?: array{caption?: ?string, image_url?: ?string}, thread?: list<array{author?: ?string, text: string, from_account?: bool}>}  $context
     */
    public function draft(Connection|YoutubeAccount $account, string $platform, array $context): string
    {
        $agent = self::agentFor($account);

        if (! $agent) {
            throw new RuntimeException('No AI agent is linked to this account.');
        }

        $image = self::postImage($platform, $context['post']['image_url'] ?? null);

        $run = $this->hub->runCommentDraft(
            $agent,
            $this->instruction($account, $platform, $context, $image !== null),
            $platform,
            self::clean($context['author'] ?? null) ?: 'Commenter',
            $image ? [['type' => 'image', 'url' => $image, 'detail' => 'auto', 'name' => 'post.jpg']] : [],
        );

        $draft = self::unquote(trim((string) ($run->output_message ?? '')));

        if ($draft === '') {
            throw new RuntimeException('The AI agent returned an empty draft.');
        }

        return mb_substr($draft, 0, self::MAX_REPLY[$platform]);
    }

    /**
     * One self-contained instruction. Worded without words like "human" or
     * "attendant": the whole string is a USER message on the hub, which scans
     * it for hand-off requests.
     */
    private function instruction(Connection|YoutubeAccount $account, string $platform, array $context, bool $withImage): string
    {
        $network = self::NETWORK_LABEL[$platform];
        $accountName = $account instanceof YoutubeAccount ? $account->title : $account->name;
        $limit = self::MAX_REPLY[$platform];

        $lines = [
            "Write a reply to a public comment on {$network}, on behalf of the account \"{$accountName}\". Someone on the team reviews it before it is posted.",
            '',
            'Rules:',
            '- Reply in the language the comment is written in.',
            '- Keep it short, the way a brand answers under a post: one to three sentences.',
            '- It is public. Do not invent prices, stock, links, order details or promises; when the commenter needs those, invite them to send a direct message.',
            '- Do not start with the commenter\'s @handle: the reply already appears under their comment.',
            '- Answer with the reply text only — no quotes, no notes, no alternatives.',
            "- Never more than {$limit} characters.",
            '',
        ];

        $caption = self::clean($context['post']['caption'] ?? null, 1500);

        $lines[] = $caption !== ''
            ? "Post caption: {$caption}"
            : 'Post caption: (none)';

        if ($withImage) {
            $lines[] = 'The post\'s picture is attached.';
        }

        $author = self::clean($context['author'] ?? null) ?: 'someone';
        $lines[] = '';
        $lines[] = "Comment by {$author}: ".self::clean($context['text'] ?? '', 2200);

        $thread = array_slice($context['thread'] ?? [], -20);

        if ($thread !== []) {
            $lines[] = '';
            $lines[] = 'Replies already under this comment, oldest first:';

            foreach ($thread as $reply) {
                $who = ! empty($reply['from_account'])
                    ? 'Your account'
                    : (self::clean($reply['author'] ?? null) ?: 'someone');

                $lines[] = "- {$who}: ".self::clean($reply['text'] ?? '', 600);
            }
        }

        return implode("\n", $lines);
    }

    /** A post picture the hub may fetch: https, on the network's own image host. */
    public static function postImage(string $platform, ?string $url): ?string
    {
        if (! $url || ! str_starts_with($url, 'https://')) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::IMAGE_HOSTS[$platform] ?? [] as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return $url;
            }
        }

        return null;
    }

    private static function clean(?string $text, int $max = 100): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    /** Models like to wrap a single line in quotes even when told not to. */
    private static function unquote(string $text): string
    {
        if (preg_match('/^(["“”«])(.*)(["“”»])$/su', $text, $match) && ! str_contains($match[2], '"')) {
            return trim($match[2]);
        }

        return $text;
    }
}
