<?php

namespace App\Services\Message;

use App\Models\Connection;
use App\Services\Connection\WhatsApp\WhatsappTemplateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The text a template send actually put in front of the customer.
 *
 * A send carries only the template's name, its language and the values for
 * whatever it leaves blank — the wording itself lives on Meta. So the thread
 * used to record `chamado` where the customer had read "Olá! Tudo bem com
 * você?", which makes the inbox preview, the search index and every later
 * reader of that conversation wrong about what was said.
 *
 * Resolved here rather than passed in by the caller because not every caller
 * has it: the campaign runner stores components, and POST /api/v1/leads is
 * called by somebody else's system that only knows the template's name.
 */
class TemplateBody
{
    /**
     * How long a WABA's template list is reused.
     *
     * Meta has no endpoint for editing an approved template, so the wording is
     * effectively immutable and this could be far longer. It stays short
     * because a template can be deleted and recreated under the same name, and
     * a wrong line written into a thread is one nobody can correct afterwards.
     */
    private const CACHE_TTL_SECONDS = 900;

    public function __construct(private WhatsappTemplateService $templates) {}

    /**
     * What this send put on the customer's screen: the body as they read it,
     * and — when the template carries a header, a footer or buttons — the same
     * shape an interactive message is recorded in, so the panel can draw it the
     * way they saw it.
     *
     * `body` is null when the wording cannot be known, and the caller keeps
     * whatever it used before rather than losing the message.
     *
     * @param  array<int, array<string, mixed>>|null  $components  the send's own components
     * @return array{body: ?string, interactive: ?array<string, mixed>}
     */
    public function describe(Connection $connection, string $name, string $language, ?array $components = null): array
    {
        $template = $this->find($connection, $name, $language);

        if ($template === null) {
            return ['body' => null, 'interactive' => null];
        }

        $text = $this->bodyTextOf($template);
        $body = $text === '' ? null : $this->fill($text, $this->textValues($components, 'body'));

        return [
            'body' => $body,
            'interactive' => $this->interactiveOf($template, $body, $components),
        ];
    }

    /**
     * The body text alone, for callers that only record a line.
     */
    public function render(Connection $connection, string $name, string $language, ?array $components = null): ?string
    {
        return $this->describe($connection, $name, $language, $components)['body'];
    }

    /**
     * A template's header, footer and buttons in the Cloud API's own
     * interactive shape.
     *
     * ⚠️ Deliberately the *interactive* shape, and stored under that key: a
     * template with buttons is an interactive message as far as the person
     * reading it is concerned, and the panel already knows how to draw one.
     * A second shape would mean a second renderer drifting from the first.
     *
     * Null when the template is nothing but body text — the ordinary text
     * bubble already shows that correctly, and this would only add weight to
     * every row.
     *
     * Carousel cards are left out: their buttons live per card, in a shape this
     * does not carry, and the body is the part that was wrong.
     *
     * @param  array<string, mixed>  $template
     * @param  array<int, array<string, mixed>>|null  $components  the send's own components
     * @return array<string, mixed>|null
     */
    private function interactiveOf(array $template, ?string $body, ?array $components): ?array
    {
        $header = null;
        $footer = null;
        $buttons = [];

        foreach ($template['components'] ?? [] as $component) {
            switch (strtoupper((string) ($component['type'] ?? ''))) {
                case 'HEADER':
                    $format = strtoupper((string) ($component['format'] ?? 'TEXT'));

                    if ($format === 'TEXT') {
                        if (($component['text'] ?? '') !== '') {
                            $header = [
                                'type' => 'text',
                                'text' => $this->fill((string) $component['text'], $this->textValues($components, 'header')),
                            ];
                        }

                        break;
                    }

                    // A media header is the picture at the top of the bubble.
                    // Its address comes from the send, never from the
                    // template: what the template carries is the sample Meta
                    // approved, a signed URL of theirs that has since expired.
                    $link = $this->mediaLink($components, $format);

                    if ($link !== null) {
                        $header = ['type' => strtolower($format), strtolower($format) => ['link' => $link]];
                    }
                    break;

                case 'FOOTER':
                    if (($component['text'] ?? '') !== '') {
                        $footer = ['text' => (string) $component['text']];
                    }
                    break;

                case 'BUTTONS':
                    foreach ($component['buttons'] ?? [] as $index => $button) {
                        $title = (string) ($button['text'] ?? '');

                        if ($title === '') {
                            continue;
                        }

                        // Every kind draws as one tappable row, which is what
                        // the customer sees; what a tap *does* differs, and
                        // that is on their phone, not in this record.
                        $buttons[] = [
                            'type' => 'reply',
                            'reply' => ['id' => 'tpl_btn_' . $index, 'title' => $title],
                        ];
                    }
                    break;
            }
        }

        if ($header === null && $footer === null && $buttons === []) {
            return null;
        }

        return array_filter([
            'type' => 'button',
            'header' => $header,
            'body' => $body === null ? null : ['text' => $body],
            'footer' => $footer,
            'action' => $buttons === [] ? null : ['buttons' => $buttons],
        ], fn ($value) => $value !== null);
    }

    /**
     * The template by name and language.
     *
     * Language is part of a template's identity — the same name exists once per
     * language, with different wording in each — so a mismatch is not something
     * to paper over. The one exception is a name that exists in a single
     * language: there is nothing to confuse it with, and a send whose language
     * string is spelled differently still refers to that one.
     *
     * @return array<string, mixed>|null
     */
    private function find(Connection $connection, string $name, string $language): ?array
    {
        $named = array_values(array_filter(
            $this->all($connection),
            fn ($template) => ($template['name'] ?? null) === $name,
        ));

        foreach ($named as $template) {
            if (($template['language'] ?? null) === $language) {
                return $template;
            }
        }

        return count($named) === 1 ? $named[0] : null;
    }

    /**
     * Every template on the connection's WABA.
     *
     * ⚠️ A failure is deliberately not cached: this runs on the send path, and
     * caching an empty list would make one Meta hiccup label fifteen minutes of
     * messages with the template's name instead of its text.
     *
     * @return array<int, array<string, mixed>>
     */
    private function all(Connection $connection): array
    {
        $waba = $connection->credentials['business_account_id'] ?? null;

        if (! $waba) {
            return [];
        }

        $key = 'wa-templates:' . $waba;
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $list = $this->templates->list($connection);
        } catch (\Throwable $e) {
            Log::warning('Could not read the templates to record what an outgoing one said', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        Cache::put($key, $list, self::CACHE_TTL_SECONDS);

        return $list;
    }

    /** @param  array<string, mixed>  $template */
    private function bodyTextOf(array $template): string
    {
        foreach ($template['components'] ?? [] as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return (string) ($component['text'] ?? '');
            }
        }

        return '';
    }

    /**
     * The text values this send supplied for one component, keyed by the token
     * they replace: the variable's name where the template uses named
     * parameters, its 1-based position where they are numbered. That is the
     * Cloud API's own rule, so it needs no reading of the template's text.
     *
     * ⚠️ Per component, not pooled: a header's `{{1}}` and a body's `{{1}}` are
     * different variables filled from different parameter lists, and reading
     * the body's values into the header prints the wrong name.
     *
     * @param  array<int, array<string, mixed>>|null  $components
     * @return array<string, string>
     */
    private function textValues(?array $components, string $type): array
    {
        $values = [];

        foreach ($components ?? [] as $component) {
            if (strtolower((string) ($component['type'] ?? '')) !== $type) {
                continue;
            }

            foreach (array_values($component['parameters'] ?? []) as $index => $parameter) {
                if (strtolower((string) ($parameter['type'] ?? '')) !== 'text') {
                    continue;
                }

                $token = (string) ($parameter['parameter_name'] ?? $index + 1);
                $values[$token] = (string) ($parameter['text'] ?? '');
            }
        }

        return $values;
    }

    /**
     * The media address this send supplied for its header, if any.
     *
     * @param  array<int, array<string, mixed>>|null  $components
     */
    private function mediaLink(?array $components, string $format): ?string
    {
        $key = strtolower($format);

        foreach ($components ?? [] as $component) {
            if (strtolower((string) ($component['type'] ?? '')) !== 'header') {
                continue;
            }

            foreach ($component['parameters'] ?? [] as $parameter) {
                $link = $parameter[$key]['link'] ?? null;

                if (is_string($link) && $link !== '') {
                    return $link;
                }
            }
        }

        return null;
    }

    /**
     * Substitute each token, leaving unfilled ones visible — the same rule the
     * dashboard's own preview follows.
     *
     * ⚠️ The replacement goes through a callback, never a replacement string:
     * `$` and `\` in it would otherwise be read as backreferences, and in this
     * market a body variable holding a price ("R$ 89,90") is routine.
     *
     * @param  array<string, string>  $values
     */
    private function fill(string $text, array $values): string
    {
        foreach ($values as $token => $value) {
            if ($value === '') {
                continue;
            }

            $text = preg_replace_callback(
                '/\{\{\s*' . preg_quote($token, '/') . '\s*\}\}/',
                fn () => $value,
                $text,
            ) ?? $text;
        }

        return $text;
    }
}
