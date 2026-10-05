<?php

namespace App\Services\FlowAssistant;

use App\Models\Flow;
use App\Models\FlowEdge;
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\FlowGraph;
use App\Services\Flow\FlowTexts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A copy of a flow that speaks another language.
 *
 * Always a copy. The flow being translated is usually the one answering
 * customers right now, and a translation is something to read through before
 * it is put in front of anybody — so the original is never written to, and the
 * result is a new flow no connection points at.
 *
 * The model translates strings and never sees the graph: what it returns is
 * put back into the exact fields it came from (FlowTexts), so the copy has the
 * same nodes, branches, variables and media as the source by construction.
 * Each string is then checked on the way in — a translation that lost a
 * {{placeholder}} would send customers a sentence with a hole in it, so it is
 * discarded and the original kept; one longer than its field is cut to fit.
 */
class FlowTranslator
{
    /**
     * What can be asked for: the code the dashboard sends, the name the model
     * is given, and the name the copy is labelled with.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const LANGUAGES = [
        'pt-BR' => ['Brazilian Portuguese', 'Português'],
        'en' => ['English', 'English'],
        'es' => ['Spanish', 'Español'],
        'id' => ['Indonesian', 'Bahasa Indonesia'],
        'fr' => ['French', 'Français'],
        'it' => ['Italian', 'Italiano'],
        'de' => ['German', 'Deutsch'],
        'nl' => ['Dutch', 'Nederlands'],
        'tr' => ['Turkish', 'Türkçe'],
        'ar' => ['Arabic', 'العربية'],
        'hi' => ['Hindi', 'हिन्दी'],
        'ja' => ['Japanese', '日本語'],
    ];

    /** Characters of source text per model call. */
    private const BATCH_CHARS = 5000;

    private const BATCH_STRINGS = 40;

    public function __construct(
        private readonly FlowAssistantService $assistant,
    ) {}

    /**
     * @return array{flow: Flow, translated: int, kept: int, notes: array<string, int>}
     */
    public function translate(Flow $source, string $language): array
    {
        [$languageName, $label] = self::LANGUAGES[$language];

        $graph = FlowGraph::export($source);
        $nodes = $graph['nodes'];
        $texts = FlowTexts::collect($nodes);

        $translated = 0;
        $kept = 0;

        foreach ($this->batches($texts) as $batch) {
            $answers = $this->assistant->translate(
                array_map(fn (int $i) => ['id' => "t{$i}", 'text' => $texts[$i]['text'], 'max' => $texts[$i]['max']], $batch),
                $languageName,
            );

            foreach ($batch as $i) {
                $text = $this->accept($texts[$i], $answers["t{$i}"] ?? null);

                if ($text === null) {
                    $kept++;

                    continue;
                }

                $nodes = FlowTexts::put($nodes, $texts[$i]['node'], $texts[$i]['path'], $text);
                $translated++;
            }
        }

        // The copy holds the same graph with different words, so this passes
        // whenever the source would — it is here for the day that stops being
        // true, which should fail here rather than as a flow that cannot be
        // opened.
        FlowBlueprint::validateNodes($nodes);

        $flow = DB::transaction(function () use ($source, $graph, $nodes, $label) {
            $flow = Flow::create([
                'tenant_id' => $source->tenant_id,
                'name' => Str::limit($source->name, 255 - mb_strlen($label) - 3, '').' ('.$label.')',
            ]);

            $keyToId = [];

            foreach ($nodes as $node) {
                $keyToId[$node['key']] = $flow->nodes()->create([
                    'type' => $node['type'],
                    'data' => $node['data'],
                    'position_x' => $node['position_x'],
                    'position_y' => $node['position_y'],
                ])->id;
            }

            foreach ($graph['edges'] as $edge) {
                FlowEdge::create([
                    'source_node_id' => $keyToId[$edge['source_key']],
                    'target_node_id' => $keyToId[$edge['target_key']],
                    'condition_value' => $edge['condition_value'],
                ]);
            }

            $flow->update(['last_updated_at' => now()]);

            return $flow;
        });

        Log::info('FlowTranslator: flow translated', [
            'source_flow_id' => $source->id,
            'flow_id' => $flow->id,
            'language' => $language,
            'translated' => $translated,
            'kept' => $kept,
        ]);

        return [
            'flow' => $flow,
            'translated' => $translated,
            'kept' => $kept,
            'notes' => $this->notes($graph['nodes']),
        ];
    }

    /**
     * The translation to store, or null to keep the original.
     *
     * @param  array{text: string, max: int|null}  $source
     */
    private function accept(array $source, ?string $answer): ?string
    {
        if ($answer === null || trim($answer) === '') {
            return null;
        }

        if ($this->placeholders($answer) !== $this->placeholders($source['text'])) {
            return null;
        }

        if ($source['max'] !== null && mb_strlen($answer) > $source['max']) {
            $answer = rtrim(mb_substr($answer, 0, $source['max']));
        }

        return $answer;
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $text, $matches);

        $names = $matches[1];
        sort($names);

        return $names;
    }

    /**
     * @param  list<array{text: string}>  $texts
     * @return list<list<int>> indexes into $texts
     */
    private function batches(array $texts): array
    {
        $batches = [];
        $current = [];
        $size = 0;

        foreach ($texts as $i => $text) {
            $length = mb_strlen($text['text']);

            if ($current !== [] && ($size + $length > self::BATCH_CHARS || count($current) >= self::BATCH_STRINGS)) {
                $batches[] = $current;
                $current = [];
                $size = 0;
            }

            $current[] = $i;
            $size += $length;
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * What a translation cannot do for the person, counted so the dashboard
     * can say so: recorded audio and video still speak the old language, a
     * condition still compares against the old words, and an AI agent still
     * follows its own instructions.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, int>
     */
    private function notes(array $nodes): array
    {
        $media = 0;
        $conditions = 0;
        $agents = 0;

        foreach ($nodes as $node) {
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];

            switch ($node['type']) {
                case 'message':
                    $bubbles = is_array($data['messages'] ?? null) && $data['messages'] !== [] ? $data['messages'] : [$data];

                    foreach ($bubbles as $bubble) {
                        if (in_array($bubble['message_type'] ?? null, ['audio', 'video'], true)) {
                            $media++;
                        }
                    }
                    break;

                case 'condition':
                    $value = trim((string) ($data['value'] ?? ''));

                    if ($value !== '' && ! is_numeric($value) && in_array($data['operator'] ?? null, ['equals', 'not_equals', 'contains', 'not_contains'], true)) {
                        $conditions++;
                    }
                    break;

                case 'ai_agent':
                case 'ai_tools':
                    $agents++;
                    break;
            }
        }

        return array_filter(['media' => $media, 'conditions' => $conditions, 'ai_agents' => $agents]);
    }
}
