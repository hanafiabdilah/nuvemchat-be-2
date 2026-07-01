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
        $before = $request->input('before');
        $limit = (int) $request->input('limit', 100);
        $limit = max(1, min($limit, 500));

        $query = Message::whereHas('conversation', function($q){
            $q->whereHas('connection', function($q){
                $q->where('tenant_id', Auth::user()->tenant_id);
            });
        })->orderBy('id', 'DESC');

        // Delta sync: only messages touched since the last sync (edits, new).
        if ($since !== null && $since !== '') {
            $query->where('updated_at', '>', $since);
        }

        // Cursor pagination (newest-first). Client walks backwards by passing
        // `before` = smallest id it already holds, until has_more is false.
        if ($before !== null && $before !== '') {
            $query->where('id', '<', $before);
        }

        // Fetch one extra row to detect whether older/more messages remain.
        $messages = $query->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;
        $messages = $messages->take($limit);

        return response()->json([
            'data' => MessageResource::collection($messages),
            'has_more' => $hasMore,
            'next_before' => $hasMore ? $messages->last()?->id : null,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
