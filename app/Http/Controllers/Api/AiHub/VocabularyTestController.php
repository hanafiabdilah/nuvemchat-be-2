<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Enums\Connection\Channel;
use App\Exceptions\Billing\AiRunQuotaExceededException;
use App\Exceptions\Billing\CreditExhaustedException;
use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Api\AiHub\Concerns\ResolvesAiHubTenant;
use App\Http\Controllers\Controller;
use App\Models\AiHubAgent;
use App\Models\Tenant;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\AiAgentHub\AiAttachments;
use App\Services\AiAgentHub\AiDeliveryPolicy;
use App\Services\AiAgentHub\AiTranscription;
use App\Services\AiAgentHub\AiVocabulary;
use App\Services\AiAgentHub\AiVoiceReply;
use App\Services\Media\MediaStorage;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The audio vocabulary's test bench: hear what the list does without sending
 * a voice note to a real number first.
 *
 * Two directions, like the list itself — `listen` transcribes a recording with
 * the workspace's terms, `speak` voices a sentence with its pronunciations.
 * The hub only produces either as part of an agent run, so both are one real
 * run on the agent chosen on screen: billed, counted against the plan, and
 * recorded like any other (AiAgentHubTenantService::runAudioTest).
 *
 * Both test the list **as it is on screen**, saved or not. Trying a spelling
 * before committing it is the point; making someone save a guess, test it,
 * and save again to undo it would turn the bench into a way to break the
 * live list.
 */
class VocabularyTestController extends Controller
{
    use ResolvesAiHubTenant;

    /** Long enough for a paragraph full of jargon; short enough to stay a test. */
    private const MAX_TEXT = 500;

    /** When the platform sets no audio ceiling, a test upload still has one. */
    private const FALLBACK_MAX_KB = 25 * 1024;

    /**
     * Only the transcript matters, so the agent is asked for as little else as
     * possible — its reply is paid for in tokens and thrown away.
     */
    private const LISTEN_PROMPT = '[audio] Isto é um teste de transcrição feito no painel. Não responda ao áudio: escreva apenas "ok".';

    /**
     * The reply text *is* what gets voiced, so the agent is asked to repeat the
     * sentence verbatim. The markers keep a sentence that looks like a question
     * from being answered instead of read.
     */
    private const SPEAK_PROMPT = "Isto é um teste de pronúncia feito no painel. Responda somente com o texto entre as linhas --- abaixo, exatamente como está escrito: sem cumprimentar, sem acrescentar nada e sem corrigir ou traduzir.\n---\n%s\n---";

    /**
     * What a browser recording or an uploaded file is guessed as, mapped to the
     * container name the transcription model and AiAttachments know it by.
     * `weba` is Symfony's name for audio/webm; `mpga` its first for audio/mpeg.
     */
    private const EXTENSION_ALIASES = [
        'weba' => 'webm',
        'mpga' => 'mp3',
        'mpeg' => 'mp3',
        'oga' => 'ogg',
    ];

    public function __construct(private AiAgentHubTenantService $hub)
    {
    }

    public function listen(Request $request): JsonResponse
    {
        $this->decodeTerms($request);

        $accepted = array_values(array_unique(array_merge(
            AiAttachments::audioExtensions(),
            array_keys(self::EXTENSION_ALIASES),
        )));

        $validated = $request->validate(array_merge($this->sharedRules(), [
            'audio' => ['required', 'file', 'max:' . $this->maxUploadKb(), 'mimes:' . implode(',', $accepted)],
        ]));

        $agent = $this->agent((int) $validated['ai_hub_agent_id']);
        $tenant = $this->previewTenant($request, $validated);

        if (! AiAttachments::audioEnabled()) {
            return response()->json([
                'message' => 'Voice note transcription is switched off on this platform.',
                'code' => 'audio_input_disabled',
            ], 409);
        }

        $file = $request->file('audio');
        $extension = $this->containerFor($file->guessExtension(), $file->getClientOriginalExtension());

        // Stored only for as long as the run takes: the hub fetches the file
        // itself, by the same kind of signed link a stored voice note travels
        // as, and nothing about a test recording is worth keeping afterwards.
        $path = $file->storeAs('vocabulary-tests/' . $tenant->id, Str::uuid() . '.' . $extension, MediaStorage::diskName());

        try {
            $attachments = [[
                'type' => 'audio',
                'mimeType' => AiAttachments::audioMimeFor($extension),
                'url' => MediaStorage::signedUrl($path, now()->addMinutes(15)),
                'name' => 'teste-vocabulario.' . $extension,
            ]];

            $inputAudio = AiTranscription::options(
                ['input_audio' => array_filter([
                    'provider' => $validated['provider'] ?? null,
                    'credential_id' => $validated['credential_id'] ?? null,
                ])],
                $attachments,
                $tenant,
            );

            $run = $this->hub->runAudioTest($agent, self::LISTEN_PROMPT, attachments: $attachments, inputAudio: $inputAudio);
        } catch (AiRunQuotaExceededException|CreditExhaustedException|UpstreamServiceException $e) {
            return $this->refusal($e);
        } finally {
            MediaStorage::disk()->delete($path);
        }

        $item = (array) data_get($run->metadata, 'inputAudio.items.0', []);
        $transcript = trim((string) ($item['text'] ?? ''));

        if ($transcript === '') {
            // Silence and a transcription stage that quietly produced nothing
            // look identical from here; the run row keeps what the hub said.
            Log::warning('VocabularyTestController: the listening test came back without a transcript', [
                'ai_hub_run_id' => $run->id,
                'input_audio' => data_get($run->metadata, 'inputAudio'),
            ]);

            return response()->json([
                'message' => 'No speech was recognised in the recording.',
                'code' => 'transcript_empty',
            ], 422);
        }

        return response()->json([
            'data' => [
                'transcript' => $transcript,
                'provider' => strtolower((string) ($inputAudio['provider'] ?? '')) ?: null,
                'model' => $item['model'] ?? $inputAudio['model'] ?? $inputAudio['transcriptionModel'] ?? null,
                'matches' => $this->heard($transcript, AiVocabulary::dictionary($tenant)),
            ],
        ]);
    }

    public function speak(Request $request): JsonResponse
    {
        $this->decodeTerms($request);

        $validated = $request->validate(array_merge($this->sharedRules(), [
            'text' => ['required', 'string', 'max:' . self::MAX_TEXT],
            'voice' => ['nullable', 'string', 'max:40'],
            'voice_id' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:64'],
            'speed' => ['nullable', 'numeric', 'between:0.25,4'],
        ]));

        $agent = $this->agent((int) $validated['ai_hub_agent_id']);
        $tenant = $this->previewTenant($request, $validated);

        $requested = AiTranscription::provider($validated['provider'] ?? null, (string) config('ai.voice.provider', AiTranscription::OPENAI));

        // Read through the node's own rules, so the bench speaks exactly the
        // way a flow would: the same fallback to OpenAI when ElevenLabs has no
        // voice, the same kill switch.
        $config = AiVoiceReply::config(['response_audio' => array_filter([
            'mode' => AiDeliveryPolicy::MODE_AUDIO_ONLY,
            'provider' => $validated['provider'] ?? null,
            'credential_id' => $validated['credential_id'] ?? null,
            'voice' => $validated['voice'] ?? null,
            'voice_id' => $validated['voice_id'] ?? null,
            'model' => $validated['model'] ?? null,
            'speed' => $validated['speed'] ?? null,
        ], fn ($value) => $value !== null && $value !== '')]);

        if (! $config['enabled']) {
            return response()->json([
                'message' => 'Voice replies are switched off on this platform.',
                'code' => 'voice_reply_disabled',
            ], 409);
        }

        $responseAudio = AiVoiceReply::options($config, Channel::Messenger, $tenant);

        // MP3 whatever the platform forces for channels: pronunciation is the
        // same in any container, and Ogg/Opus does not play in every browser.
        if ($config['provider'] === AiTranscription::ELEVENLABS) {
            $responseAudio['outputFormat'] = 'mp3_44100_128';
        } else {
            $responseAudio['format'] = 'mp3';
        }

        try {
            $run = $this->hub->runAudioTest(
                $agent,
                sprintf(self::SPEAK_PROMPT, trim($validated['text'])),
                responseAudio: $responseAudio,
            );

            $audio = (array) data_get($run->metadata, 'responseAudio', []);
            $url = $audio['url'] ?? null;

            if (($audio['status'] ?? null) !== 'generated' || ! is_string($url) || $url === '') {
                // The run completed and the voice did not: the hub reports that
                // inside `output.audio`, not as a failed run.
                throw UpstreamError::exception(
                    UpstreamProvider::AiHub,
                    'voice test audio ' . ($audio['status'] ?? 'missing') . ': ' . json_encode($audio['error'] ?? null),
                    upstreamCode: 'voice_not_generated',
                    context: ['ai_hub_run_id' => $run->id],
                );
            }

            $file = AiVoiceReply::download($url, 'mp3', $audio['mimeType'] ?? null);

            if ($file === null) {
                throw UpstreamError::exception(
                    UpstreamProvider::AiHub,
                    'voice test audio could not be downloaded',
                    upstreamCode: 'voice_not_downloaded',
                    context: ['ai_hub_run_id' => $run->id],
                );
            }
        } catch (AiRunQuotaExceededException|CreditExhaustedException|UpstreamServiceException $e) {
            return $this->refusal($e);
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        @unlink($file->getRealPath());

        $mime = strtolower(trim(explode(';', (string) ($audio['mimeType'] ?? $file->getClientMimeType()))[0])) ?: 'audio/mpeg';
        $text = trim((string) ($run->output_message ?? ''));

        return response()->json([
            'data' => [
                // Inline rather than the hub's link: that one expires, and a
                // browser that cannot reach the hub directly would play nothing.
                'audio' => 'data:' . $mime . ';base64,' . base64_encode($bytes),
                'text' => $text,
                'provider' => $config['provider'],
                'fell_back_to_default' => $requested === AiTranscription::ELEVENLABS && $config['provider'] !== AiTranscription::ELEVENLABS,
                'pronunciations_enabled' => (bool) config('ai.voice.pronunciation'),
                'pronunciations' => $this->pronounced($text, AiVocabulary::pronunciations($tenant)),
            ],
        ]);
    }

    /** @return array<string, array<int, string>> */
    private function sharedRules(): array
    {
        return [
            'ai_hub_agent_id' => ['required', 'integer'],
            'provider' => ['nullable', 'in:' . AiTranscription::OPENAI . ',' . AiTranscription::ELEVENLABS],
            'credential_id' => ['nullable', 'string', 'max:64'],
            // The same rules as saving, so what can be tested is what can be kept.
            'terms' => ['sometimes', 'array', 'max:' . AiVocabulary::MAX_TERMS],
            'terms.*.term' => ['required', 'string', 'min:2', 'max:50'],
            'terms.*.aliases' => ['sometimes', 'array', 'max:' . AiVocabulary::MAX_ALIASES],
            'terms.*.aliases.*' => ['string', 'min:2', 'max:50'],
            'terms.*.speak_as' => ['nullable', 'string', 'max:' . AiVocabulary::MAX_SPEAK_AS_LENGTH],
        ];
    }

    /**
     * A recording travels as multipart, where a list of objects has no honest
     * shape — and an empty list has none at all, which would silently test the
     * saved list instead of the one someone just cleared. So it comes as JSON.
     */
    private function decodeTerms(Request $request): void
    {
        $raw = $request->input('terms');

        if (! is_string($raw)) {
            return;
        }

        $decoded = json_decode($raw, true);

        // Left as a string when it is not a list, so the `array` rule says so.
        $request->merge(['terms' => is_array($decoded) ? $decoded : $raw]);
    }

    /** The workspace with the on-screen list in place of the saved one, never persisted. */
    private function previewTenant(Request $request, array $validated): Tenant
    {
        $tenant = $request->user()->tenant;

        if (! array_key_exists('terms', $validated)) {
            return $tenant;
        }

        $preview = clone $tenant;
        $preview->audio_dictionary = AiVocabulary::sanitize($validated['terms']) ?: null;

        return $preview;
    }

    private function agent(int $id): AiHubAgent
    {
        return $this->aiHubTenant()->agents()->findOrFail($id);
    }

    private function maxUploadKb(): int
    {
        $bytes = (int) config('ai.audio.max_bytes', 0);

        return $bytes > 0 ? max(1, intdiv($bytes, 1024)) : self::FALLBACK_MAX_KB;
    }

    private function containerFor(?string $guessed, ?string $client): string
    {
        foreach ([$guessed, $client] as $candidate) {
            $extension = strtolower((string) $candidate);
            $extension = self::EXTENSION_ALIASES[$extension] ?? $extension;

            if (AiAttachments::audioMimeFor($extension) !== null) {
                return $extension;
            }
        }

        // The mimes rule has already vouched for the bytes; this is only the
        // name the hub reads the container from.
        return 'webm';
    }

    /**
     * Which terms the transcript contains, and how.
     *
     * `term` is the list working. `alias` is the transcription still producing
     * one of the other spellings — the entry is known, the model just did not
     * write it the right way — which is the one thing worth telling apart.
     *
     * @param  array<int, array{term: string, aliases: array<int, string>, speak_as: ?string}>  $dictionary
     * @return array<int, array{term: string, heard_as: string, status: string}>
     */
    private function heard(string $transcript, array $dictionary): array
    {
        $matches = [];

        foreach ($dictionary as $entry) {
            if (self::contains($transcript, $entry['term'])) {
                $matches[] = ['term' => $entry['term'], 'heard_as' => $entry['term'], 'status' => 'term'];

                continue;
            }

            foreach ($entry['aliases'] as $alias) {
                if (self::contains($transcript, $alias)) {
                    $matches[] = ['term' => $entry['term'], 'heard_as' => $alias, 'status' => 'alias'];

                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * The pronunciations the written reply gave the voice a reason to use.
     *
     * @param  array<int, array{term: string, speakAs: string, aliases?: array<int, string>}>  $replacements
     * @return array<int, array{term: string, speak_as: string}>
     */
    private function pronounced(string $text, array $replacements): array
    {
        $applied = [];

        foreach ($replacements as $replacement) {
            foreach (array_merge([$replacement['term']], $replacement['aliases'] ?? []) as $spelling) {
                if (self::contains($text, $spelling)) {
                    $applied[] = ['term' => $replacement['term'], 'speak_as' => $replacement['speakAs']];

                    break;
                }
            }
        }

        return $applied;
    }

    /**
     * Case-insensitive, and on word edges: "IA" is not inside "Iara", and the
     * acronyms this list is mostly made of are exactly what a plain substring
     * search would find everywhere.
     */
    private static function contains(string $haystack, string $needle): bool
    {
        return (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/iu', $haystack);
    }

    private function refusal(\Throwable $e): JsonResponse
    {
        return match (true) {
            // Same codes and statuses as "Respond with AI": the screen that
            // fixes each is different, and the client already knows both.
            $e instanceof AiRunQuotaExceededException => response()->json([
                'message' => 'Your plan\'s AI runs for this billing period are used up.',
                'code' => 'ai_quota_exceeded',
                'limit' => $e->limit,
                'used' => $e->used,
            ], 402),
            $e instanceof CreditExhaustedException => response()->json([
                'message' => 'Your credit balance is empty. Top it up to keep using AI.',
                'code' => 'credit_exhausted',
                'balance_cents' => $e->balanceCents,
            ], 402),
            // Already translated and logged with a reference by UpstreamError.
            $e instanceof UpstreamServiceException => $e->toResponse(),
        };
    }
}
