<?php

namespace App\Support;

/**
 * Reading Laravel's log files, without reading all of one.
 *
 * ⚠️ Both log viewers used to `file_get_contents()` the selected file, then
 * parse every entry in it, then throw away all but the newest thousand. On a
 * quiet install that is invisible; on a busy day it is not. A single daily log
 * here routinely carries every webhook body, every queue failure and every
 * upstream error verbatim, and the PHP process has a memory limit — so opening
 * the page against a large file does not return a slow answer, it returns a
 * fatal error, and does it for every operator who tries. That is a denial of
 * service on the one screen somebody opens *because* something is already
 * wrong.
 *
 * The window below is the fix, and it is sound rather than a compromise: the
 * viewers show newest-first and cap what they show, so everything outside the
 * tail was going to be discarded anyway. What changes is that it is no longer
 * loaded in order to be discarded.
 *
 * The whole file is still reachable — `download` streams it — so nothing is
 * hidden, only paged.
 */
final class LogFile
{
    /**
     * How much of the end of a file is read.
     *
     * Sized so a normal day fits entirely (making the window invisible in
     * practice) while a runaway log cannot take the page down with it.
     */
    public const MAX_SCAN_BYTES = 8 * 1024 * 1024;

    /** Every log file, newest-looking first. @return list<string> */
    public static function files(): array
    {
        $paths = glob(storage_path('logs').'/*.log') ?: [];

        return collect($paths)
            ->map(fn (string $p) => basename($p))
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * Resolve a requested name against the files that exist, which is what
     * makes path traversal impossible: nothing outside this list is reachable.
     * Falls back to the newest file.
     */
    public static function resolve(?string $name): ?string
    {
        $files = self::files();

        if ($files === []) {
            return null;
        }

        return ($name !== null && in_array($name, $files, true)) ? $name : $files[0];
    }

    public static function path(string $file): string
    {
        return storage_path('logs').'/'.$file;
    }

    /**
     * Parse the tail of a log file into entries, oldest first.
     *
     * Entries begin with a `[timestamp] channel.LEVEL:` header; everything up
     * to the next such header (including multi-line stack traces) belongs to
     * that entry. The first header found in the window is where parsing starts,
     * so a record cut in half by the window boundary is dropped rather than
     * reported with a missing beginning.
     *
     * @return array{
     *     entries: array<int, array{timestamp:string,channel:string,level:string,message:string,stack:string,raw:string}>,
     *     size: int,
     *     scanned: int,
     *     truncated: bool,
     * }
     */
    public static function read(string $path, int $maxBytes = self::MAX_SCAN_BYTES): array
    {
        $empty = ['entries' => [], 'size' => 0, 'scanned' => 0, 'truncated' => false];

        $size = @filesize($path);

        if ($size === false || $size === 0) {
            return $empty;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return $empty;
        }

        $truncated = $size > $maxBytes;

        try {
            if ($truncated) {
                fseek($handle, -$maxBytes, SEEK_END);
            }

            $content = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if (! is_string($content) || $content === '') {
            return ['entries' => [], 'size' => $size, 'scanned' => 0, 'truncated' => $truncated];
        }

        return [
            'entries' => self::parse($content),
            'size' => $size,
            'scanned' => strlen($content),
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array<int, array{timestamp:string,channel:string,level:string,message:string,stack:string,raw:string}>
     */
    private static function parse(string $content): array
    {
        $pattern = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:\d{2})?)\]\s+([\w.\-]+)\.(\w+):/m';

        if (! preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $entries = [];
        $count = count($matches);

        for ($i = 0; $i < $count; $i++) {
            $start = $matches[$i][0][1];
            $end = ($i + 1 < $count) ? $matches[$i + 1][0][1] : strlen($content);
            $raw = rtrim(substr($content, $start, $end - $start));

            $newlinePos = strpos($raw, "\n");
            $header = $newlinePos === false ? $raw : substr($raw, 0, $newlinePos);
            $stack = $newlinePos === false ? '' : trim(substr($raw, $newlinePos + 1));

            $message = trim(preg_replace($pattern, '', $header) ?? '');

            $entries[] = [
                'timestamp' => $matches[$i][1][0],
                'channel' => $matches[$i][2][0],
                'level' => strtolower($matches[$i][3][0]),
                'message' => $message,
                'stack' => $stack,
                'raw' => $raw,
            ];
        }

        return $entries;
    }
}
