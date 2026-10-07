<?php

namespace App\Services\Message\Apiway;

/**
 * The button a customer tapped on WhatsApp API Way, read off the whatsmeow
 * `Message` node.
 *
 * WhatsApp has four spellings for "they tapped something", depending on how
 * the buttons were sent and which app answered; the core passes whichever one
 * arrived. The flow engine and the thread only need two facts out of any of
 * them — the id we sent and the label the customer saw — so they are read
 * here, once, and case-insensitively: whatsmeow's JSON spells the same field
 * `selectedButtonID` or `selectedButtonId` depending on its version.
 */
class ButtonReply
{
    /**
     * @return array{id: ?string, title: ?string}|null
     */
    public static function from(?array $message): ?array
    {
        if (! is_array($message)) {
            return null;
        }

        $message = self::lower($message);

        // The label is a protobuf oneof, which whatsmeow's JSON nests one
        // level down: {"Response": {"SelectedDisplayText": …}} beside a
        // top-level selectedButtonID. Both places are read.
        if (is_array($node = $message['buttonsresponsemessage'] ?? null)) {
            return self::reply(
                $node['selectedbuttonid'] ?? null,
                $node['selecteddisplaytext'] ?? $node['response']['selecteddisplaytext'] ?? null,
            );
        }

        if (is_array($node = $message['templatebuttonreplymessage'] ?? null)) {
            return self::reply(
                $node['selectedid'] ?? null,
                $node['selecteddisplaytext'] ?? $node['response']['selecteddisplaytext'] ?? null,
            );
        }

        if (is_array($node = $message['listresponsemessage'] ?? null)) {
            return self::reply($node['singleselectreply']['selectedrowid'] ?? null, $node['title'] ?? null);
        }

        if (is_array($node = $message['interactiveresponsemessage'] ?? null)) {
            $params = $node['nativeflowresponsemessage']['paramsjson'] ?? null;
            $params = is_string($params) ? json_decode($params, true) : null;

            return self::reply(
                is_array($params) ? ($params['id'] ?? null) : null,
                $node['body']['text'] ?? (is_array($params) ? ($params['display_text'] ?? null) : null),
            );
        }

        return null;
    }

    private static function reply(mixed $id, mixed $title): ?array
    {
        $id = is_scalar($id) && (string) $id !== '' ? (string) $id : null;
        $title = is_scalar($title) && trim((string) $title) !== '' ? trim((string) $title) : null;

        return $id === null && $title === null ? null : ['id' => $id, 'title' => $title];
    }

    private static function lower(array $node): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            $out[is_string($key) ? strtolower($key) : $key] = is_array($value) ? self::lower($value) : $value;
        }

        return $out;
    }
}
