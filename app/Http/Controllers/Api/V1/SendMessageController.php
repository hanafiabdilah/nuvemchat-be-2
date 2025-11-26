<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageService;
use Illuminate\Http\Request;
use League\Config\Exception\ValidationException;

class SendMessageController extends Controller
{
    public function __construct(
        protected SendMessageService $sendMessageService
    ){}

    public function handle(Request $request)
    {
        $connection = Connection::where('api_key', request()->header('X-Api-Key'))->firstOrFail();

        try {
            $this->sendMessageService->send($connection, $request->all());

            return response()->json([
                'message' => 'Message sent successfully'
            ], 201);
        } catch(ValidationException $th){
            return $th;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to send message',
            ], 500);
        }
    }
}
