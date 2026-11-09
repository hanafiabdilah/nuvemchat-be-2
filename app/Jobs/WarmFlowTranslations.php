<?php

namespace App\Jobs;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Services\Flow\FlowLocalization;
use App\Services\Flow\FlowTexts;
use App\Services\FlowAssistant\FlowAssistantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Translate the rest of a flow into a language a customer just turned up in.
 *
 * Queued the first time a node misses a translation, so that the customer's
 * next node is read from the table instead of waiting on the AI while they
 * watch. Only what is not stored yet is sent, in batches small enough that
 * one slow answer does not cost the whole flow.
 *
 * Best effort and a single attempt: whatever this does not get to, the node
 * itself translates when a customer reaches it.
 */
class WarmFlowTranslations implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Several AI runs back to back; see the batch limits below. */
    public int $timeout = 600;

    /** A batch is bounded twice: by how many strings and by how much text. */
    private const BATCH_STRINGS = 40;

    private const BATCH_CHARS = 5000;

    public function __construct(
        public int $flowId,
        public string $language,
    ) {}

    public function handle(FlowLocalization $localization, FlowAssistantService $assistant): void
    {
        $flow = Flow::with('tenant')->find($this->flowId);
        $tenant = $flow?->tenant;

        if (! $flow?->translate_to_customer || ! $tenant || ! $localization->usable($tenant)) {
            return;
        }

        $texts = [];

        foreach (FlowNode::where('flow_id', $flow->id)->get(['id', 'type', 'data']) as $node) {
            foreach (is_array($node->data) ? FlowTexts::ofNode($node->type->value, $node->data) : [] as $text) {
                $texts[FlowLocalization::hash($text['text'])] ??= $text;
            }
        }

        $missing = array_diff_key($texts, FlowLocalization::stored($tenant, $this->language, array_keys($texts)));

        if ($missing === []) {
            return;
        }

        try {
            $assistant->assertReady($tenant);

            foreach ($this->batches(array_values($missing)) as $batch) {
                $localization->translateNow($tenant, $batch, $this->language, 120);
            }
        } catch (Throwable $e) {
            Log::warning('WarmFlowTranslations: stopped before the whole flow was translated', [
                'flow_id' => $flow->id,
                'language' => $this->language,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<array{text: string, max: int|null}>  $texts
     * @return list<list<array{text: string, max: int|null}>>
     */
    private function batches(array $texts): array
    {
        $batches = [];
        $batch = [];
        $chars = 0;

        foreach ($texts as $text) {
            $length = mb_strlen($text['text']);

            if ($batch !== [] && (count($batch) >= self::BATCH_STRINGS || $chars + $length > self::BATCH_CHARS)) {
                $batches[] = $batch;
                $batch = [];
                $chars = 0;
            }

            $batch[] = $text;
            $chars += $length;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }
}
