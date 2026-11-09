<?php

namespace App\Services\Flow;

use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Exceptions\Billing\CreditExhaustedException;
use App\Exceptions\UserFacingException;
use App\Jobs\WarmFlowTranslations;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\FlowTextTranslation;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionGate;
use App\Services\Conversation\SystemMessage;
use App\Services\FlowAssistant\FlowAssistantService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/**
 * A flow that answers each customer in the customer's own language.
 *
 * The author writes the flow once, in their language. When a node is about to
 * speak, its texts are swapped — in memory, on the model the engine is
 * holding — for the same texts in the language the customer writes in. Nothing
 * is ever written back to the flow: the next customer, in another language,
 * gets the same node in theirs.
 *
 * Three rules shape everything here:
 *
 * 1. **The customer is never left waiting on a translation.** Every failure —
 *    no AI key, an empty balance, a slow or broken run, a model that mangled a
 *    `{{placeholder}}` — sends the original text. A flow in the wrong language
 *    is a worse flow; a flow that stopped is a lost customer.
 *
 * 2. **Paid for once.** Translations are kept by source text and language
 *    (`flow_text_translations`), so a node is translated the first time a
 *    customer in that language reaches it and read from the table forever
 *    after. The first miss in a language also queues the rest of the flow
 *    ({@see WarmFlowTranslations}), so the next node is usually already there.
 *
 * 3. **The language is the customer's, detected once.** From what they wrote,
 *    stored on the contact, and never overwritten once an agent has set it by
 *    hand. Nothing is guessed from a phone number or a name.
 *
 * Runs on the workspace's own flow-assistant key — the same AI the builder
 * uses — so the switch is only offered where that key exists.
 */
class FlowLocalization
{
    public const SOURCE_DETECTED = 'detected';

    public const SOURCE_MANUAL = 'manual';

    /** Note codes, translated by the dashboard (lib/infoMessage.ts). */
    public const NOTE_DETECTED = 'flow_language_detected';

    public const NOTE_UNAVAILABLE = 'flow_translation_unavailable';

    /** How many of the customer's latest messages the detector reads. */
    private const DETECT_MESSAGES = 5;

    /**
     * After a failure, how long translation stays off for the workspace.
     * Without it, every node of every conversation would wait out the same
     * timeout against the same broken key.
     */
    private const PAUSE_MINUTES = 5;

    /** @var WeakMap<FlowNode, string>|null nodes already handled, and in which language */
    private static ?WeakMap $done = null;

    public function __construct(
        private FlowAssistantService $assistant,
        private SubscriptionGate $gate,
    ) {}

    /**
     * Swap a node's texts for the customer's language, in place.
     *
     * Safe to call on every path that is about to make a node speak, as often
     * as it likes: a node already handled in this process is left alone.
     */
    public function localize(FlowState $flowState, ?FlowNode $node): void
    {
        if ($node === null || ! config('flow.translation.enabled', true)) {
            return;
        }

        self::$done ??= new WeakMap;

        if (isset(self::$done[$node])) {
            return;
        }

        // Marked before any work, so a failure below does not make every
        // later caller in the same process try again.
        self::$done[$node] = '';

        $type = $node->type->value;
        $data = $node->data;

        if (! FlowTexts::speaks($type) || ! is_array($data)) {
            return;
        }

        $flow = $node->flow;

        if (! $flow?->translate_to_customer) {
            return;
        }

        $texts = FlowTexts::ofNode($type, $data);

        if ($texts === []) {
            return;
        }

        $conversation = $flowState->conversation;
        $tenant = $flow->tenant;

        if (! $conversation || ! $tenant || ! $this->usable($tenant) || $conversation->type === ConversationType::Group) {
            return;
        }

        try {
            $target = $this->customerLanguage($conversation, $flow, $tenant);

            if ($target === null || FlowLanguages::same($target, FlowLanguages::ofFlow($flow, $tenant))) {
                return;
            }

            $translated = $this->translations($tenant, $flow, $conversation, $texts, $target);
        } catch (Throwable $e) {
            // Every expected failure is handled inside; this is the net for
            // the unexpected one. The node still speaks — in the original.
            Log::warning('FlowLocalization: could not localize a node, sending the original', [
                'node_id' => $node->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $changed = false;

        foreach ($texts as $text) {
            $answer = $translated[self::hash($text['text'])] ?? null;

            if ($answer !== null && $answer !== $text['text']) {
                data_set($data, $text['path'], self::fit($answer, $text['max']));
                $changed = true;
            }
        }

        if ($changed) {
            $node->data = $data;
            self::$done[$node] = $target;
        }
    }

    /**
     * Whether this node is carrying translated texts right now.
     *
     * The engine needs to know one thing about that: a customer who types the
     * title of a menu option may type it in the language the author wrote,
     * so matching tries the original texts too.
     */
    public static function isLocalized(FlowNode $node): bool
    {
        return self::$done !== null && (self::$done[$node] ?? '') !== '';
    }

    /**
     * The customer's language: stored on the contact, or detected now from
     * what they have written in this conversation.
     */
    public function customerLanguage(Conversation $conversation, Flow $flow, Tenant $tenant): ?string
    {
        $contact = $conversation->contact_id ? Contact::find($conversation->contact_id) : null;

        if ($contact === null) {
            return null;
        }

        if ($stored = FlowLanguages::normalize($contact->language)) {
            return $stored;
        }

        $messages = $conversation->messages()
            ->where('sender_type', SenderType::Incoming)
            ->where('message_type', MessageType::Text)
            ->whereNotNull('body')
            ->latest('id')
            ->limit(self::DETECT_MESSAGES)
            ->get(['id', 'body'])
            ->reverse();

        $sample = trim($messages->pluck('body')->implode("\n"));

        // Numbers, emojis and "ok" are in no language; asking the model about
        // them is paying to hear "und".
        if (! preg_match('/\p{L}{2,}/u', $sample) || $this->paused($tenant)) {
            return null;
        }

        // One answer per message: until the customer writes something new,
        // asking again would buy the same "cannot tell".
        $key = "flow-language:{$contact->id}:" . (int) $messages->last()?->id;
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached === 'und' ? null : $cached;
        }

        try {
            $this->assistant->assertReady($tenant);
            $language = $this->assistant->detectLanguage($tenant, $sample, (int) config('flow.translation.detect_timeout', 15));
        } catch (Throwable $e) {
            $this->failed($tenant, $conversation, $e, 'detect');

            return null;
        }

        Cache::put($key, $language ?? 'und', now()->addDay());

        if ($language === null) {
            return null;
        }

        // A conditional write: an agent may have set the language by hand
        // while the model was thinking, and theirs wins.
        $written = Contact::whereKey($contact->id)
            ->whereNull('language')
            ->update(['language' => $language, 'language_source' => self::SOURCE_DETECTED]);

        if ($written && ! FlowLanguages::same($language, FlowLanguages::ofFlow($flow, $tenant))) {
            SystemMessage::info(
                $conversation,
                "Customer language detected: {$language}. The flow answers in it.",
                self::NOTE_DETECTED,
                ['language' => $language],
            );
        }

        return $written ? $language : FlowLanguages::normalize(Contact::whereKey($contact->id)->value('language'));
    }

    /**
     * Translations for these texts, from the table where they exist and from
     * the AI where they do not.
     *
     * @param  list<array{path: string, text: string, max: int|null}>  $texts
     * @return array<string, string> keyed by source hash
     */
    private function translations(Tenant $tenant, Flow $flow, Conversation $conversation, array $texts, string $language): array
    {
        $wanted = [];

        foreach ($texts as $text) {
            $wanted[self::hash($text['text'])] = $text;
        }

        $found = self::stored($tenant, $language, array_keys($wanted));
        $missing = array_diff_key($wanted, $found);

        if ($missing === [] || $this->paused($tenant)) {
            return $found;
        }

        try {
            $this->assistant->assertReady($tenant);
            $found += $this->translateNow($tenant, array_values($missing), $language, (int) config('flow.translation.timeout', 20));
        } catch (Throwable $e) {
            $this->failed($tenant, $conversation, $e, 'translate');

            return $found;
        }

        // The rest of the flow, in the background, so the next node this
        // customer reaches is usually already in the table. Once per flow and
        // language at a time.
        if (config('flow.translation.warm', true)
            && Cache::add("flow-translation:warm:{$flow->id}:{$language}", 1, now()->addMinutes(30))) {
            WarmFlowTranslations::dispatch($flow->id, $language);
        }

        return $found;
    }

    /**
     * Ask the AI for these texts and keep what it answers.
     *
     * A translation that dropped or renamed a `{{placeholder}}` is refused and
     * the original stored in its place: the variable would otherwise go out
     * as a literal — or not at all — to every customer in that language, and
     * asking again gets the same model making the same mistake.
     *
     * @param  list<array{text: string, max: int|null}>  $texts
     * @return array<string, string> keyed by source hash
     */
    public function translateNow(Tenant $tenant, array $texts, string $language, int $timeout): array
    {
        $strings = [];

        foreach ($texts as $text) {
            $strings[self::hash($text['text'])] = [
                'id' => self::hash($text['text']),
                'text' => $text['text'],
                'max' => $text['max'] ?? null,
            ];
        }

        $answers = $this->assistant->translate($tenant, array_values($strings), FlowLanguages::describe($language), $timeout);
        $kept = [];

        foreach ($strings as $hash => $string) {
            if (! array_key_exists($hash, $answers)) {
                continue;
            }

            $answer = trim($answers[$hash]);

            $kept[$hash] = ($answer !== '' && self::placeholders($answer) === self::placeholders($string['text']))
                ? self::fit($answer, $string['max'])
                : $string['text'];
        }

        if ($kept !== []) {
            $now = now();

            FlowTextTranslation::upsert(
                array_map(fn (string $hash) => [
                    'tenant_id' => $tenant->id,
                    'language' => $language,
                    'source_hash' => $hash,
                    'text' => $kept[$hash],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], array_keys($kept)),
                ['tenant_id', 'language', 'source_hash'],
                ['text', 'updated_at'],
            );
        }

        return $kept;
    }

    /**
     * @param  list<string>  $hashes
     * @return array<string, string>
     */
    public static function stored(Tenant $tenant, string $language, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        return FlowTextTranslation::where('tenant_id', $tenant->id)
            ->where('language', $language)
            ->whereIn('source_hash', $hashes)
            ->pluck('text', 'source_hash')
            ->all();
    }

    /**
     * Whether this workspace can translate at all: the platform has the AI
     * hub, the plan includes the flow assistant, and translation is not
     * paused after a failure a moment ago.
     */
    public function usable(Tenant $tenant): bool
    {
        if (! FlowAssistantService::available()) {
            return false;
        }

        return ! config('services.billing.enforce') || $this->gate->feature($tenant, 'flow_assistant');
    }

    private function paused(Tenant $tenant): bool
    {
        return Cache::has(self::pauseKey($tenant));
    }

    /**
     * Turn translation off for the workspace for a few minutes, and say so —
     * once per conversation — in the thread, where whoever picks it up will
     * wonder why the customer got Portuguese.
     */
    private function failed(Tenant $tenant, Conversation $conversation, Throwable $e, string $step): void
    {
        $reason = match (true) {
            $e instanceof UserFacingException && $e->getErrorCode() === FlowAssistantService::SETUP_REQUIRED => 'setup',
            $e instanceof CreditExhaustedException => 'credit',
            default => 'error',
        };

        Cache::put(self::pauseKey($tenant), $reason, now()->addMinutes(self::PAUSE_MINUTES));

        Log::warning('FlowLocalization: translation unavailable, sending the original texts', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'step' => $step,
            'reason' => $reason,
            'error' => $e->getMessage(),
        ]);

        if (Cache::add("flow-translation:noted:{$conversation->id}", 1, now()->addHours(12))) {
            SystemMessage::info(
                $conversation,
                'The flow could not be translated into the customer’s language and was sent as written.',
                self::NOTE_UNAVAILABLE,
                ['translation_reason' => $reason],
            );
        }
    }

    private static function pauseKey(Tenant $tenant): string
    {
        return "flow-translation:paused:{$tenant->id}";
    }

    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }

    /** A translation longer than the field accepts, cut to fit rather than refused by the channel. */
    private static function fit(string $text, ?int $max): string
    {
        return ($max !== null && mb_strlen($text) > $max) ? rtrim(mb_substr($text, 0, $max)) : $text;
    }

    /** @return list<string> the placeholder names in a text, sorted */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $text, $matches);
        $names = $matches[1];
        sort($names);

        return $names;
    }

    /**
     * Forget which nodes were handled — for tests, where one process plays
     * many conversations with the same node ids.
     */
    public static function flush(): void
    {
        self::$done = null;
    }
}
