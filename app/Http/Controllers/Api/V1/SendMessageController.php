<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ChannelCapabilityException;
use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SendMessageController extends Controller
{
    public function __construct(
        protected SendMessageService $sendMessageService
    ){}

    public function handle(Request $request)
    {
        $connection = Connection::where('api_key', request()->header('X-Api-Key'))->firstOrFail();

        try {
            $this->sendMessageService->sendMessage($connection, $request->all());

            return response()->json([
                'message' => 'Message sent successfully'
            ], 201);
        } catch(ValidationException $th){
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
