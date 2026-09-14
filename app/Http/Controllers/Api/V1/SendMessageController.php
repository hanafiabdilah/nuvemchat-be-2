<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Exceptions\ChannelCapabilityException;
use App\Exceptions\PublicApiException;
use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/send-message — send a text through one of the workspace's
 * connections.
 *
 * Authenticated by the workspace's API key (V1\ApiKeyAuth); the connection is
 * named in the body as `connection_id`, its public id. The rest of the body is
 * channel-specific and validated by that channel's handler.
 */
class SendMessageController extends Controller
{
    /** The channels SendMessageFactory has a handler for. */
    public const CHANNELS = [
        Channel::WhatsappOfficial,
        Channel::WhatsappApiway,
        Channel::Instagram,
        Channel::Messenger,
        Channel::Telegram,
        Channel::Discord,
        Channel::TikTok,
    ];

    public function __construct(
        protected SendMessageService $sendMessageService
    ) {}

    public function handle(Request $request)
    {
        $request->validate([
            'connection_id' => ['required', 'string', 'max:40'],
        ]);

        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        $connection = Connection::where('tenant_id', $key->tenant_id)
            ->where('public_id', $request->input('connection_id'))
            ->first();

        if (! $connection) {
            throw ValidationException::withMessages([
                'connection_id' => 'Conexão não encontrada nesta conta. Copie o ID em Conexões, nos detalhes da conexão.',
            ]);
        }

        // Refused here rather than left to the factory, which throws a bare
        // InvalidArgumentException that would surface as a 500.
        if (! in_array($connection->channel, self::CHANNELS, true)) {
            throw new PublicApiException(
                "A conexão \"{$connection->name}\" é de um canal que não envia mensagens pela API.",
                'channel_not_supported',
            );
        }

        if ($connection->status !== ConnectionStatus::Active) {
            throw new PublicApiException(
                "A conexão \"{$connection->name}\" não está ativa. Reconecte-a no Pingly ou informe outra connection_id.",
                'connection_inactive',
            );
        }

        try {
            $this->sendMessageService->sendMessage($connection, $request->except('connection_id'));

            return response()->json([
                'message' => 'Message sent successfully',
            ], 201);
        } catch (ValidationException $th) {
            throw $th;
        } catch (UpstreamServiceException $th) {
            // SendMessageService already translated whatever the channel said —
            // a Telegram SDK exception ("bot was blocked by the user"), Meta's
            // OAuth codes, a Discord refusal — and logged the original with a
            // reference.
            return $th->toResponse();
        } catch (ChannelCapabilityException $th) {
            return response()->json(['message' => $th->getMessage()], 422);
        } catch (\Throwable $th) {
            Log::error('V1 send message failed', [
                'connection_id' => $connection->id,
                'exception' => $th::class,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível enviar a mensagem. Tente novamente em instantes.',
            ], 500);
        }
    }
}
