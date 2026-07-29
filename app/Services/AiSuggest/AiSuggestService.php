<?php

namespace App\Services\AiSuggest;

use App\Enums\Message\SenderType;
use App\Models\Conversation;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * "Respond with AI" — drafts a reply suggestion for the agent from the recent
 * conversation transcript. The result is only pasted into the composer; the
 * agent edits and sends it manually, so nothing here touches the send path.
 *
 * Providers are called directly over HTTP with the tenant's own API key
 * (tenants.ai_suggest_config, encrypted): openai, gemini or anthropic.
 */
class AiSuggestService
{
    public const PROVIDERS = ['openai', 'gemini', 'anthropic'];

    public const DEFAULT_MODELS = [
        'openai' => 'gpt-4o-mini',
        'gemini' => 'gemini-2.0-flash',
        'anthropic' => 'claude-opus-5',
    ];

    /** How many recent messages to include in the prompt. */
    protected const TRANSCRIPT_LIMIT = 20;

    public function suggest(Conversation $conversation): string
    {
        $config = $conversation->connection->tenant->ai_suggest_config;

        $provider = $config['provider'] ?? null;
        $apiKey = $config['api_key'] ?? null;

        if (!in_array($provider, self::PROVIDERS, true) || empty($apiKey)) {
            throw new RuntimeException('AI suggestions are not configured for this account.');
        }

        $model = trim((string) ($config['model'] ?? '')) ?: self::DEFAULT_MODELS[$provider];

        [$system, $messages] = $this->buildPrompt($conversation);

        if (empty($messages)) {
            throw new RuntimeException('There are no messages to base a suggestion on.');
        }

        return match ($provider) {
            'openai' => $this->suggestWithOpenAi($apiKey, $model, $system, $messages),
            'gemini' => $this->suggestWithGemini($apiKey, $model, $system, $messages),
            'anthropic' => $this->suggestWithAnthropic($apiKey, $model, $system, $messages),
        };
    }

    /**
     * Build [system prompt, chat transcript] from the last messages.
     * Transcript entries are ['role' => 'user'|'assistant', 'content' => string]
     * — 'user' is the customer, 'assistant' is the agent/bot side.
     */
    protected function buildPrompt(Conversation $conversation): array
    {
        $contactName = $conversation->contact?->name ?: 'the customer';

        $system = <<<PROMPT
You are assisting a customer support agent on an omnichannel messaging platform.
Based on the conversation transcript, draft the agent's next reply to the customer ({$contactName}).

Rules:
- Reply in the same language the customer is writing in.
- Be helpful, friendly and concise, in a tone appropriate for chat messaging.
- Output ONLY the message text to send — no quotes, labels, options or explanations.
- If information is missing to fully resolve the request, ask the customer a clear follow-up question instead of inventing facts.
PROMPT;

        $recent = $conversation->messages()
            ->whereNull('unsend_at')
            ->orderByDesc('id')
            ->limit(self::TRANSCRIPT_LIMIT)
            ->get()
            ->reverse();

        $messages = [];

        foreach ($recent as $message) {
            $role = $message->sender_type === SenderType::Incoming ? 'user' : 'assistant';

            $body = trim((string) ($message->body ?? ''));
            if ($body === '') {
                $type = $message->message_type?->value ?? 'message';
                $body = $type === 'text' ? '' : "[{$type}]";
            }
            if ($body === '') {
                continue;
            }

            // Merge consecutive same-role messages so every provider accepts
            // the transcript (some require strict user/assistant alternation).
            $lastIndex = count($messages) - 1;
            if ($lastIndex >= 0 && $messages[$lastIndex]['role'] === $role) {
                $messages[$lastIndex]['content'] .= "\n" . $body;
            } else {
                $messages[] = ['role' => $role, 'content' => $body];
            }
        }

        // Providers require the transcript to start with a user turn.
        if (!empty($messages) && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        // And to end with one (no assistant prefill): if the agent spoke last,
        // turn the request into an explicit follow-up instruction.
        if (!empty($messages) && $messages[count($messages) - 1]['role'] !== 'user') {
            $messages[] = [
                'role' => 'user',
                'content' => '[The customer has not replied yet. Draft an appropriate follow-up message from the agent.]',
            ];
        }

        return [$system, $messages];
    }

    protected function suggestWithOpenAi(string $apiKey, string $model, string $system, array $messages): string
    {
        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'max_completion_tokens' => 1024,
                'messages' => array_merge(
                    [['role' => 'system', 'content' => $system]],
                    $messages
                ),
            ]);

        if ($response->failed()) {
            $this->failFromProvider('OpenAI', $response->json('error.message'), $response->status());
        }

        $text = trim((string) $response->json('choices.0.message.content'));

        if ($text === '') {
            throw new RuntimeException('OpenAI returned an empty suggestion.');
        }

        return $text;
    }

    protected function suggestWithGemini(string $apiKey, string $model, string $system, array $messages): string
    {
        $contents = array_map(fn (array $message) => [
            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $message['content']]],
        ], $messages);

        $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout(60)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => $contents,
                'generationConfig' => ['maxOutputTokens' => 1024],
            ]);

        if ($response->failed()) {
            $this->failFromProvider('Gemini', $response->json('error.message'), $response->status());
        }

        $text = trim((string) $response->json('candidates.0.content.parts.0.text'));

        if ($text === '') {
            throw new RuntimeException('Gemini returned an empty suggestion.');
        }

        return $text;
    }

    protected function suggestWithAnthropic(string $apiKey, string $model, string $system, array $messages): string
    {
        $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 1024,
                'system' => $system,
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            $this->failFromProvider('Anthropic', $response->json('error.message'), $response->status());
        }

        // Safety classifiers can decline with HTTP 200 + stop_reason "refusal" —
        // content may be empty, so check before reading it.
        if ($response->json('stop_reason') === 'refusal') {
            throw new RuntimeException('Anthropic declined to draft a suggestion for this conversation.');
        }

        $text = '';
        foreach ((array) $response->json('content') as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('Anthropic returned an empty suggestion.');
        }

        return $text;
    }

    protected function failFromProvider(string $provider, ?string $message, int $status): never
    {
        $detail = $message ? ": {$message}" : " (HTTP {$status})";

        throw new RuntimeException("{$provider} request failed{$detail}");
    }
}
