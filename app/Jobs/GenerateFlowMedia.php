<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One step of an AI media node's generation: start it, or look again.
 *
 * A generation outlives any single job — a video takes minutes — so each run
 * does one thing and, while the hub is still working, queues the next look.
 * One attempt per step: the step after it is the retry.
 */
class GenerateFlowMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(
        public int $generationId,
    ) {}

    public function handle(): void
    {
        (new FlowExecutor)->runAiMedia($this->generationId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('GenerateFlowMedia: a media generation step died', [
            'ai_media_generation_id' => $this->generationId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
