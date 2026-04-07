<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConnectionController extends Controller
{
    public function instagramCallback(Request $request)
    {
        Log::info('Instagram OAuth callback received', [
            'query' => $request->query(),
            'body' => $request->all(),
        ]);

        return response()->json([
            'message' => 'Instagram OAuth callback received',
        ]);
    }

    public function instagramDeauthorize(Request $request)
    {
        Log::info('Instagram deauthorization received', [
            'query' => $request->query(),
        ]);

        return response()->json([
            'message' => 'Instagram deauthorization received',
        ]);
    }

    public function instagramDataDeletion(Request $request)
    {
        Log::info('Instagram data deletion request received', [
            'query' => $request->query(),
        ]);

        return response()->json([
            'message' => 'Instagram data deletion request received',
        ]);
    }
}
