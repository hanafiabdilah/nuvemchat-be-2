<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $since = $request->input('since');

        $messages = Message::whereHas('conversation', function($q){
            $q->whereHas('connection', function($q){
                $q->where('user_id', Auth::id());
            });
        })->where('updated_at', '>', $since)->orderBy('sent_at', 'DESC')->orderBy('id', 'DESC')->get();

        return response()->json([
            'data' => MessageResource::collection($messages),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
