<?php

namespace App\Support\Errors;

use App\Exceptions\UpstreamServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single place an outside system's words are turned into ours.
 *
 * Every integration this platform talks to answers failures in its own dialect,
 * written for whoever maintains *it*: "provider must be one of the following
 * values: OPENAI, ANTHROPIC", "(#131047) Re-engagement message", "node_error /
 * not connected", "platform_capacity_reached". Those strings used to travel
 * unchanged into a toast in front of a business owner, and every one of them
 * fails the same way — it names a system the customer does not have, in a
 * vocabulary they cannot act on, for a problem that is usually ours.
 *
 * So the raw text stops here. It is logged, with a short reference the customer
 * can quote back, and what leaves is copy about the thing the customer actually
 * bought: their connection, their agent, their campaign.
 *
 * Two rules the dictionaries below are built on:
 *
 *  - Only translate into something *actionable*. "A janela de 24 horas expirou,
 *    use um template" earns its specificity; inventing a confident-sounding
 *    cause for a failure we have never seen is worse than admitting we do not
 *    know, so unrecognised failures get the vague copy plus the reference.
 *  - Never blame the customer for our plumbing. Missing platform credentials,
 *    a hub that rejects a field we sent, a partner token that expired: those
 *    read as "estamos com um problema", never as "verifique seus dados".
 */
final class UpstreamError
{
    /**
     * Translate and log; hand back an exception ready to throw.
     *
     * @param  string|null  $raw  The upstream's own words. Logged, never shown.
     * @param  string|null  $upstreamCode  Their error code, when they send one.
     * @param  array<string, mixed>  $context  Extra fields for the log line.
     */
    public static function exception(
        UpstreamProvider $provider,
        ?string $raw,
        ?string $upstreamCode = null,
        int $status = 502,
        array $context = [],
        ?\Throwable $previous = null,
    ): UpstreamServiceException {
        [$code, $message, $httpStatus] = self::resolve($provider, $raw, $upstreamCode, $status);
        $ref = self::log($provider, $raw, $upstreamCode, $status, $code, $context);

        return new UpstreamServiceException(
            provider: $provider,
            userMessage: $message,
            errorCode: $code,
            httpStatus: $httpStatus,
            reference: $ref,
            rawMessage: $raw,
            previous: $previous,
        );
    }

    /**
     * Same translation, as a response — for the controllers that return rather
     * than throw.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra  Fields the caller needs alongside
     *         the message (balances, caps): payload, never prose.
     */
    public static function response(
        UpstreamProvider $provider,
        ?string $raw,
        ?string $upstreamCode = null,
        int $status = 502,
        array $context = [],
        array $extra = [],
    ): JsonResponse {
        return self::exception($provider, $raw, $upstreamCode, $status, $context)->toResponse($extra);
    }

    /**
     * The safe sentence for a caught exception, keeping ours when it is ours.
     *
     * The catch blocks that use this sit at the bottom of long call stacks and
     * see everything: a Graph refusal, a Guzzle timeout, and — the case this
     * method exists for — a hint we already wrote for exactly this failure.
     * Translating that hint would be a silent downgrade, and the only place it
     * would show up is a customer who was told less than we knew.
     *
     * @param  array<string, mixed>  $context
     */
    public static function messageFrom(
        UpstreamProvider $provider,
        \Throwable $th,
        array $context = [],
    ): string {
        if ($th instanceof HasUserSafeMessage) {
            return $th->getMessage();
        }

        return self::message(
            $provider,
            $th->getMessage(),
            status: 502,
            context: array_merge($context, ['exception' => $th::class]),
        );
    }

    /**
     * Just the safe sentence, for the places that store a string instead of
     * answering a request — a failed campaign row, a job's status field.
     *
     * @param  array<string, mixed>  $context
     */
    public static function message(
        UpstreamProvider $provider,
        ?string $raw,
        ?string $upstreamCode = null,
        int $status = 502,
        array $context = [],
    ): string {
        return self::exception($provider, $raw, $upstreamCode, $status, $context)->getMessage();
    }

    /**
     * Whether a string looks like it came from outside.
     *
     * Used by the safety net (SanitizeUpstreamErrors) to catch leaks from call
     * sites nobody has converted yet, and only ever on error responses. It is
     * deliberately a short list of high-signal fingerprints: a heuristic that
     * over-fires would rewrite our own copy, which is a worse failure than the
     * one it is guarding against.
     */
    public static function looksExternal(string $text): bool
    {
        $probe = Str::lower($text);

        foreach (self::FINGERPRINTS as $needle) {
            if (str_contains($probe, $needle)) {
                return true;
            }
        }

        // "(#131047)" and friends — Meta's error codes, which only ever appear
        // inside Graph's own message strings.
        return (bool) preg_match('/\(#\d{2,6}\)/', $probe);
    }

    /**
     * Substrings that no sentence we wrote would ever contain: upstream host
     * names, vendor error vocabulary, transport-level noise and stack traces.
     */
    private const FINGERPRINTS = [
        // Hosts we call.
        'api-ia.ipbr.pro',
        'whats-api.ipbr.pro',
        'portal.apiway.com.br',
        'graph.facebook.com',
        'api.telegram.org',
        'discord.com/api',
        'api.mercadopago.com',
        'api.elevenlabs.io',
        'api.openai.com',
        'business-api.tiktok.com',
        // Vendor vocabulary.
        'oauthexception',
        'fbtrace_id',
        'must be one of the following values',
        'should not exist',
        'should not be empty',
        'must be a string',
        'must be an object',
        'node_error',
        'access_token',
        'invalid_grant',
        'invalid_client',
        // Transport / runtime noise.
        'curl error',
        'client error:',
        'server error:',
        'sqlstate',
        'stack trace',
        '/var/www/',
        'guzzlehttp',
    ];

    /**
     * @return array{0: string,1: string, 2: int}  [code, message, httpStatus]
     */
    private static function resolve(UpstreamProvider $provider, ?string $raw, ?string $upstreamCode, int $status): array
    {
        $needle = self::normalize($raw);
        $code = Str::lower(trim((string) $upstreamCode));

        $mapped = match ($provider) {
            UpstreamProvider::AiHub => self::aiHub($needle, $code),
            UpstreamProvider::ApiwayPartner => self::apiwayPartner($needle, $code),
            UpstreamProvider::ApiwayCore => self::apiwayCore($needle, $code),
            UpstreamProvider::ApiwayNumbers => self::apiwayNumbers($needle, $code),
            UpstreamProvider::Meta => self::meta($needle, $code),
            UpstreamProvider::Telegram => self::telegram($needle, $code),
            UpstreamProvider::Discord => self::discord($needle, $code),
            UpstreamProvider::TikTok => self::tiktok($needle, $code),
            UpstreamProvider::MercadoPago => self::mercadoPago($needle, $code),
            UpstreamProvider::Email => self::email($needle, $code),
            UpstreamProvider::Unknown => null,
        };

        if ($mapped !== null) {
            return [$mapped[0], $mapped[1], $mapped[2] ?? self::statusFor($status)];
        }

        return [$provider->value.'_unavailable', self::genericFor($provider, $status), self::statusFor($status)];
    }

    /**
     * The vaguest honest sentence for a provider we could not read.
     *
     * Split on whether the upstream said "you sent something wrong" (4xx, which
     * for the customer means "the thing you asked for was not accepted") or
     * "I am broken" (everything else) — those two need different next actions
     * even when we have nothing else to say.
     */
    private static function genericFor(UpstreamProvider $provider, int $status): string
    {
        if ($status >= 400 && $status < 500 && $status !== 429) {
            return match ($provider) {
                UpstreamProvider::ApiwayNumbers => 'Não foi possível contratar este número com as opções escolhidas. Selecione outro aplicativo ou DDD e tente novamente.',
                UpstreamProvider::AiHub => 'O serviço de IA não aceitou esta configuração. Revise as opções do agente e, se persistir, fale com o suporte.',
                UpstreamProvider::ApiwayCore => 'A instância do WhatsApp não aceitou esta operação. Verifique se ela está conectada e tente novamente.',
                default => 'Não foi possível concluir esta operação. Revise os dados e tente novamente.',
            };
        }

        return match ($provider) {
            UpstreamProvider::AiHub => 'O serviço de IA está indisponível no momento. Tente novamente em instantes.',
            UpstreamProvider::ApiwayPartner => 'A contratação de instâncias está indisponível no momento. Tente novamente em instantes.',
            UpstreamProvider::ApiwayCore => 'Não foi possível falar com a instância do WhatsApp. Verifique se ela está ativa e tente novamente.',
            UpstreamProvider::ApiwayNumbers => 'A contratação de números está indisponível no momento. Tente novamente em instantes.',
            UpstreamProvider::MercadoPago => 'Não foi possível processar o pagamento agora. Tente novamente em instantes.',
            UpstreamProvider::Email => 'Não foi possível falar com o servidor de e-mail. Verifique a conexão e tente novamente.',
            default => 'Não foi possível concluir a ação no canal agora. Tente novamente em instantes.',
        };
    }

    /**
     * Never answer a 401/403 from *them* with a 401 to *us*: the SPA signs the
     * user out on any 401, so an expired provider token used to look like an
     * expired session and log the agent out mid-shift.
     */
    private static function statusFor(int $status): int
    {
        return match (true) {
            in_array($status, [401, 403, 419], true) => 502,
            $status === 429 => 429,
            $status >= 400 && $status < 500 => 422,
            $status >= 500 => 502,
            default => 502,
        };
    }

    // --- Per-provider dictionaries ------------------------------------------
    //
    // Each returns [code, message] or [code, message, httpStatus], or null to
    // fall through to the generic copy.

    /** @return array{0: string, 1: string, 2?: int}|null */
    private static function aiHub(string $m, string $code): ?array
    {
        return match (true) {
            // The one AI Hub failure with a fix the customer owns, and the one
            // that used to arrive as "Provider credential not found or disabled"
            // — which reads like a broken account rather than an unset field.
            str_contains($m, 'credential not found'),
            str_contains($m, 'credential is disabled'),
            str_contains($m, 'credential not found or disabled') => [
                'ai_credential_invalid',
                'A chave de IA usada por este agente não está mais válida. Escolha outra chave em Agentes de IA → Credenciais e salve novamente.',
                422,
            ],

            // class-validator rejecting a field we sent. Always our bug (a hub
            // older than the payload, a typo in node data), so it must not read
            // as "fix your data".
            str_contains($m, 'must be one of'),
            str_contains($m, 'should not exist'),
            str_contains($m, 'should not be empty'),
            str_contains($m, 'must be a '),
            str_contains($m, 'must be an '),
            str_contains($m, 'must be shorter'),
            str_contains($m, 'must be longer'),
            str_contains($m, 'validation failed') => [
                'ai_invalid_configuration',
                'A configuração deste agente de IA não foi aceita pelo serviço. Revise as opções do agente e, se persistir, fale com o suporte.',
                422,
            ],

            str_contains($m, 'already exists'),
            str_contains($m, 'duplicate') => [
                'ai_name_conflict',
                'Já existe um item com esse nome. Escolha outro nome e tente novamente.',
                409,
            ],

            str_contains($m, 'not found') => [
                'ai_object_missing',
                'Este item não está mais disponível no serviço de IA. Recarregue a página e tente novamente.',
                422,
            ],

            str_contains($m, 'rate limit'),
            str_contains($m, 'too many requests'),
            $code === '429' => [
                'ai_rate_limited',
                'O serviço de IA está recebendo muitas solicitações agora. Aguarde alguns instantes e tente novamente.',
                429,
            ],

            // Transcription/TTS refusals arrive worded by whoever runs the
            // model (401 missing_permissions, quota exceeded). The customer
            // does not hold that key — we do.
            str_contains($m, 'transcription failed'),
            str_contains($m, 'speech'),
            str_contains($m, 'audio') => [
                'ai_audio_unavailable',
                'Não foi possível processar o áudio no serviço de IA neste momento. Tente novamente em instantes.',
                502,
            ],

            default => null,
        };
    }

    /** @return array{0: string, 1: string, 2?: int}|null */
    private static function apiwayPartner(string $m, string $code): ?array
    {
        return match ($code) {
            // Not a refusal — a queue. The money is already taken and the hold
            // is retried hourly, so the copy must not sound like a dead end.
            'platform_capacity_reached' => [
                'apiway_capacity_hold',
                'No momento não há instâncias disponíveis. Seu pedido ficou em espera e será concluído automaticamente assim que houver vaga.',
                409,
            ],
            'no_enabled_subnet_capacity' => [
                'apiway_out_of_stock',
                'Não há instâncias disponíveis nesta localidade no momento. Escolha outra localidade ou tente novamente mais tarde.',
                409,
            ],
            'location_not_available' => [
                'apiway_location_unavailable',
                'Esta localidade não está disponível para contratação.',
                422,
            ],
            'invalid_quantity' => [
                'apiway_invalid_quantity',
                'Quantidade inválida para esta contratação.',
                422,
            ],
            'invalid_cycle' => [
                'apiway_invalid_cycle',
                'Ciclo de cobrança inválido para esta contratação.',
                422,
            ],
            'not_found' => [
                'apiway_not_found',
                'Esta instância não foi encontrada no provedor. Recarregue a página e tente novamente.',
                404,
            ],
            'invalid_body', 'invalid_state', 'confirmation_required' => [
                'apiway_rejected',
                'Não foi possível concluir esta operação com a instância. Recarregue a página e tente novamente.',
                422,
            ],
            // Platform-side configuration. Nothing the tenant can check, so the
            // copy says so instead of sending them to look at their own data.
            'partner_api_disabled', 'internal_token_platform_required', 'forbidden', 'apiway_unconfigured' => [
                'apiway_unavailable',
                'A contratação de instâncias está temporariamente indisponível. Nossa equipe já foi avisada.',
                503,
            ],
            default => null,
        };
    }

    /** @return array{0: string, 1: string, 2?: int}|null */
    private static function apiwayCore(string $m, string $code): ?array
    {
        return match (true) {
            // The session has no node behind it: the instance is no longer
            // provisioned. "not connected" sends a business owner to check
            // their phone and their wifi, the one place the problem is not.
            $code === 'node_error',
            str_contains($m, 'not connected'),
            str_contains($m, 'node_error') => [
                'apiway_instance_offline',
                'A instância não está ativa no provedor, então esta ação não pode ser concluída. Verifique a assinatura da instância ou troque a conexão para outra instância.',
                422,
            ],

            str_contains($m, 'not logged'),
            str_contains($m, 'logged out'),
            str_contains($m, 'unauthorized'),
            str_contains($m, 'invalid token') => [
                'apiway_instance_unpaired',
                'A instância não está pareada. Leia o QR Code novamente na tela da conexão.',
                422,
            ],

            str_contains($m, 'not exists'),
            str_contains($m, 'not found') => [
                'apiway_instance_missing',
                'Esta instância não existe mais no provedor. Vincule a conexão a outra instância.',
                422,
            ],

            default => null,
        };
    }

    /** @return array{0: string, 1: string, 2?: int}|null */
    private static function apiwayNumbers(string $m, string $code): ?array
    {
        return match (true) {
            $code === 'cap_reached' => [
                'numbers_out_of_stock',
                'Não há números disponíveis no momento. Tente novamente em instantes.',
                409,
            ],
            str_contains($m, 'ddd'),
            str_contains($m, 'indispon'),
            str_contains($m, 'unavailable') => [
                'numbers_option_unavailable',
                'Esta opção de número não está disponível agora. Escolha outro aplicativo ou DDD.',
                422,
            ],
            default => null,
        };
    }

    /** @return array{0: string, 1: string, 2?: int}|null */
    private static function meta(string $m, string $code): ?array
    {
        // Meta puts the numeric code inside the message as "(#131047)" as often
        // as it sends it as a field, so both are read.
        $numeric = $code !== '' ? $code : (preg_match('/\(#(\d{2,6})\)/', $m, $hit) ? $hit[1] : '');

        return match (true) {
            $numeric === '131047',
            str_contains($m, 're-engagement message'),
            str_contains($m, 'outside the allowed window'),
            str_contains($m, '24 hour') => [
                'messaging_window_closed',
                'A janela de 24 horas para responder este contato já fechou. Envie um template aprovado para reabrir a conversa.',
                422,
            ],

            // Test mode: the WABA can only message numbers registered in Meta
            // Business. Worth its own entry because it looks like a delivery
            // failure and is really an account that was never taken live —
            // the campaign will fail for every recipient until it is.
            str_contains($m, 'not in allowed list') => [
                'recipient_not_allowed',
                'Este número não está na lista de destinatários permitidos da conta do WhatsApp. '
                    . 'Enquanto a conta estiver em modo de teste, só é possível enviar para os números cadastrados no Meta Business.',
                422,
            ],

            $numeric === '131026',
            str_contains($m, 'message undeliverable') => [
                'recipient_unreachable',
                'Não foi possível entregar a mensagem. Confirme se o número tem WhatsApp ativo.',
                422,
            ],

            $numeric === '190',
            $numeric === '463',
            str_contains($m, 'session has expired'),
            str_contains($m, 'access token'),
            str_contains($m, 'oauthexception') => [
                'channel_reauth_required',
                'A conexão com este canal expirou. Reconecte a conta na tela de Conexões para voltar a enviar mensagens.',
                422,
            ],

            in_array($numeric, ['10', '200', '3'], true),
            str_contains($m, 'does not have permission'),
            str_contains($m, 'permission for this action') => [
                'channel_permission_missing',
                'A conta conectada não tem as permissões necessárias para esta ação. Reconecte o canal concedendo todas as permissões pedidas.',
                422,
            ],

            in_array($numeric, ['4', '80007', '130429', '131056', '613'], true),
            str_contains($m, 'rate limit'),
            str_contains($m, 'too many'),
            str_contains($m, 'throttl') => [
                'channel_rate_limited',
                'O canal atingiu o limite de envios por agora. Aguarde alguns minutos antes de tentar de novo.',
                429,
            ],

            // Creating a template whose name is taken. Checked before the
            // template family below, which is about *sending*: telling someone
            // to "revise the approved model" when what they hit is a name
            // collision sends them to inspect a template that is fine.
            str_contains($m, 'already exists'),
            $numeric === '2388024' => [
                'template_name_conflict',
                'Já existe um template com esse nome nesta conta do WhatsApp. Escolha outro nome.',
                422,
            ],

            str_starts_with($numeric, '132'),
            str_contains($m, 'template') => [
                'template_rejected',
                'O template usado não está aprovado ou os parâmetros não conferem com o modelo aprovado. Revise o template antes de enviar.',
                422,
            ],

            str_starts_with($numeric, '133'),
            str_contains($m, 'pin'),
            str_contains($m, 'two-step') => [
                'number_registration_failed',
                'Não foi possível registrar este número. Confirme com o provedor atual que a verificação em duas etapas está desativada e tente novamente.',
                422,
            ],

            $numeric === '131051',
            str_contains($m, 'unsupported message type') => [
                'unsupported_message_type',
                'Este tipo de mensagem não é aceito por este canal.',
                422,
            ],

            // Publishing: Meta names the exact defect ("not a valid JPEG",
            // "aspect ratio"), which is genuinely useful — but only if you know
            // it is talking about the file you just picked. Say that instead,
            // and list what it actually checks.
            in_array($numeric, ['9004', '2207003', '2207004', '2207005', '2207020', '2207026', '36003'], true),
            str_contains($m, 'not a valid jpeg'),
            str_contains($m, 'aspect ratio'),
            str_contains($m, 'media upload'),
            str_contains($m, 'unable to fetch') => [
                'media_rejected',
                'O arquivo enviado não foi aceito pelo canal. Confira o formato (JPEG ou MP4), o tamanho e a proporção da imagem ou do vídeo e tente novamente.',
                422,
            ],

            $numeric === '100',
            str_contains($m, 'invalid parameter') => [
                'channel_rejected_content',
                'O canal não aceitou este conteúdo. Revise o texto, o anexo e o destinatário e tente novamente.',
                422,
            ],

            default => null,
        };
    }

    /** @return array{0: string, 1: string, 2?: int}|null */
    private static function telegram(string $m, string $code): ?array
    {
        return match (true) {
            str_contains($m, 'blocked by the user') => [
                'recipient_blocked',
                'Este contato bloqueou o bot no Telegram, então não é possível enviar mensagens para ele.',
                422,
            ],
            str_contains($m, 'chat not found') => [
                'recipient_unreachable',
                'Esta conversa não existe mais no Telegram.',
                422,
            ],
            str_contains($m, 'unauthorized'),
            str_contains($m, 'bot token') => [
                'channel_reauth_required',
                'O token deste bot do Telegram não é mais válido. Atualize as credenciais da conexão.',
                422,
            ],
            str_contains($m, 'too many requests'),
            str_contains($m, 'retry after') => [
                'channel_rate_limited',
                'O Telegram limitou os envios por agora. Aguarde alguns minutos antes de tentar de novo.',
                429,
            ],
            default => null,
        };
    }

    /** @return array{0: string, 1: string, 2?: int}|null */
    private static function discord(string $m, string $code): ?array
    {
        return match (true) {
            str_contains($m, 'cannot send messages to this user'),
            $code === '50007' => [
                'recipient_unreachable',
                'Não é possível enviar mensagem direta para este usuário no Discord. Ele precisa compartilhar um servidor com o bot e aceitar DMs.',
                422,
            ],
            str_contains($m, 'missing access'),
            str_contains($m, 'missing permissions') => [
                'channel_permission_missing',
                'O bot não tem permissão para esta ação no Discord.',
                422,
            ],
            str_contains($m, 'unauthorized'),
            str_contains($m, 'invalid token'),
            $code === '401' => [
                'channel_reauth_required',
                'O token deste bot do Discord não é mais válido. Atualize as credenciais da conexão.',
                422,
            ],
            default => null,
        };
    }

    /** @return array{0: string, 1: string, 2?: int}|null */
    private static function tiktok(string $m, string $code): ?array
    {
        return match (true) {
            str_contains($m, 'window'),
            str_contains($m, 'janela') => [
                'messaging_window_closed',
                'A janela de 48 horas para responder este contato já fechou no TikTok.',
                422,
            ],
            str_contains($m, 'access token'),
            str_contains($m, 'expired') => [
                'channel_reauth_required',
                'A conexão com o TikTok expirou. Reconecte a conta na tela de Conexões.',
                422,
            ],
            default => null,
        };
    }

    /** @return array{0: string, 1: string, 2?: int}|null */
    private static function mercadoPago(string $m, string $code): ?array
    {
        return match (true) {
            str_contains($m, 'card'),
            str_contains($m, 'cartao'),
            str_contains($m, 'token') => [
                'payment_card_rejected',
                'Não foi possível usar este cartão. Confira os dados ou tente outro meio de pagamento.',
                422,
            ],
            str_contains($m, 'amount'),
            str_contains($m, 'valor'),
            str_contains($m, 'minimum') => [
                'payment_amount_invalid',
                'O valor desta cobrança não foi aceito pelo provedor de pagamentos. Ajuste o valor e tente novamente.',
                422,
            ],
            default => [
                'payment_failed',
                'Não foi possível processar o pagamento no momento. Tente novamente ou use outro meio de pagamento.',
                422,
            ],
        };
    }

    /** @return array{0: string, 1: string, 2?: int}|null */
    private static function email(string $m, string $code): ?array
    {
        return match (true) {
            str_contains($m, 'authentication'),
            str_contains($m, 'autenticacao'),
            str_contains($m, '535'),
            str_contains($m, 'invalid credentials') => [
                'email_auth_failed',
                'O servidor de e-mail recusou o usuário ou a senha. Atualize as credenciais desta caixa.',
                422,
            ],
            str_contains($m, 'certificate'),
            str_contains($m, 'ssl'),
            str_contains($m, 'tls') => [
                'email_tls_failed',
                'Não foi possível estabelecer uma conexão segura com o servidor de e-mail. Confira servidor, porta e tipo de segurança.',
                422,
            ],
            str_contains($m, 'timed out'),
            str_contains($m, 'timeout'),
            str_contains($m, 'connection refused') => [
                'email_unreachable',
                'O servidor de e-mail não respondeu. Confira servidor e porta e tente novamente.',
                502,
            ],
            default => null,
        };
    }

    // --- Plumbing -----------------------------------------------------------

    /** Lowercased, accent-free, so the dictionaries can match on plain ASCII. */
    private static function normalize(?string $raw): string
    {
        return Str::lower(Str::ascii((string) $raw));
    }

    /**
     * Write the upstream's own words down — once, here — and mint the short
     * reference that goes back to the customer.
     *
     * The reference is the whole point of logging at translation time rather
     * than at the call site: without it, "não foi possível concluir" is
     * indistinguishable from every other instance of itself in a support
     * conversation, and nobody can find the log line that explains it.
     *
     * @param  array<string, mixed>  $context
     */
    private static function log(
        UpstreamProvider $provider,
        ?string $raw,
        ?string $upstreamCode,
        int $status,
        string $code,
        array $context,
    ): string {
        $ref = Str::lower(Str::random(8));

        Log::warning('Upstream failure translated for the customer', array_merge($context, [
            'ref' => $ref,
            'provider' => $provider->value,
            'upstream_status' => $status,
            'upstream_code' => $upstreamCode,
            'upstream_message' => $raw === null ? null : Str::limit($raw, 2000, ''),
            'code' => $code,
        ]));

        return $ref;
    }
}
