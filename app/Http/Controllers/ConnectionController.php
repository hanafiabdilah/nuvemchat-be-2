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
        ]);

        return response()->json([
            'message' => 'Instagram OAuth callback received',
        ]);
    }
}
