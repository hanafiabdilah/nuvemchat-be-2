<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CallResource;
use App\Models\Call;
use App\Services\Calls\CallRecordingService;
use App\Services\Calls\CallService;
use Illuminate\Http\Request;

/**
 * The dashboard side of a WhatsApp call: what is ringing or running on the
 * connections this person can see, and the steps of answering one.
 *
 * The answering browser walks claim → answer (pre_accept) → accept, then
 * reports in with heartbeat until hangup. Every step re-reads the row: two
 * agents press Answer within the same second more often than one would think.
 */
class CallController extends Controller
{
    public function __construct(private CallService $calls) {}

    /**
     * Every live call this person may see — the cold-load counterpart of the
     * `call-updated` broadcasts, so a tab opened mid-call still shows who is on
     * the phone.
     */
    public function active(Request $request)
    {
        $calls = Call::query()
            ->visibleTo($request->user())
            ->live()
            ->with(['agent', 'contact'])
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => CallResource::collection($calls)]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['data' => new CallResource($this->find($request, $id)->load(['agent', 'contact']))]);
    }

    public function claim(Request $request, int $id)
    {
        $claimed = $this->calls->claim($this->find($request, $id), $request->user());

        return response()->json([
            'data' => new CallResource($claimed['call']),
            'offer_sdp' => $claimed['offer_sdp'],
            'ice_servers' => array_map(fn (string $url) => ['urls' => $url], config('calls.ice_servers', [])),
            'heartbeat_seconds' => (int) config('calls.heartbeat_seconds', 10),
        ]);
    }

    public function answer(Request $request, int $id)
    {
        $validated = $request->validate([
            // An SDP answer is a few kilobytes; the ceiling only stops a body
            // that is not one.
            'sdp' => ['required', 'string', 'max:30000'],
        ]);

        $call = $this->calls->answer($this->find($request, $id), $request->user(), $validated['sdp']);

        return response()->json(['data' => new CallResource($call->load(['agent', 'contact']))]);
    }

    public function accept(Request $request, int $id)
    {
        $call = $this->calls->accept($this->find($request, $id), $request->user());

        return response()->json(['data' => new CallResource($call)]);
    }

    public function decline(Request $request, int $id)
    {
        $call = $this->calls->decline($this->find($request, $id), $request->user());

        return response()->json(['data' => new CallResource($call->load(['agent', 'contact']))]);
    }

    public function hangup(Request $request, int $id)
    {
        $validated = $request->validate([
            'error' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $call = $this->calls->hangup($this->find($request, $id), $request->user(), $validated['error'] ?? null);

        return response()->json(['data' => new CallResource($call->load(['agent', 'contact']))]);
    }

    public function heartbeat(Request $request, int $id)
    {
        $call = $this->calls->heartbeat($this->find($request, $id), $request->user());

        return response()->json(['status' => $call->status->value]);
    }

    /**
     * One piece of the recording, from the browser on the call. Pieces are a
     * few seconds each and arrive in order; the server only stores them.
     */
    public function recordingPiece(Request $request, int $id, CallRecordingService $recordings)
    {
        $validated = $request->validate([
            // Ten seconds of 32 kbps audio is ~40 KB; the ceiling only stops
            // something that is not a piece of a recording.
            'chunk' => ['required', 'file', 'max:5120'],
            'index' => ['required', 'integer', 'min:0', 'max:100000'],
            'mime' => ['required', 'string', 'max:64'],
        ]);

        $recordings->storePiece($this->find($request, $id), $request->user(), $validated['chunk'], (int) $validated['index'], $validated['mime']);

        return response()->noContent();
    }

    public function recordingComplete(Request $request, int $id, CallRecordingService $recordings)
    {
        $recordings->complete($this->find($request, $id), $request->user());

        return response()->noContent();
    }

    private function find(Request $request, int $id): Call
    {
        return Call::query()->visibleTo($request->user())->findOrFail($id);
    }
}
