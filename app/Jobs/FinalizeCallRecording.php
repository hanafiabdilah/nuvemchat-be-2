<?php

namespace App\Jobs;

use App\Models\Call;
use App\Services\Calls\CallRecordingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Join a recorded call's pieces into one file and attach it to the call's note.
 * Dispatched twice on purpose — by the browser's "complete" and, a minute after
 * the call ends, as the fallback for a browser that closed first; the second
 * one finds the work done and returns.
 */
class FinalizeCallRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    // Copying the pieces + one ffmpeg pass over at most a few hours of mono audio.
    public int $timeout = 280;

    public function __construct(public int $callId) {}

    public function handle(CallRecordingService $recordings): void
    {
        $call = Call::query()->find($this->callId);

        if ($call) {
            $recordings->finalize($call);
        }
    }
}
