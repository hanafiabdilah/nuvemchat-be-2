<?php

namespace App\Jobs;

use App\Models\Call;
use App\Services\Calls\CallRecordingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Transcribe and summarise a recorded call with the connection's AI agent.
 *
 * One try: a second run is a second charge for the same minutes, and a failure
 * here leaves the recording itself untouched in the thread.
 */
class TranscribeCallRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    // The hub transcribes a long call before the model reads it.
    public int $timeout = 240;

    public function __construct(public int $callId) {}

    public function handle(CallRecordingService $recordings): void
    {
        $call = Call::query()->find($this->callId);

        if ($call) {
            $recordings->transcribe($call);
        }
    }
}
