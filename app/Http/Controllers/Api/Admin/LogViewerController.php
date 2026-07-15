<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Back Office API for reading the backend's Laravel logs (storage/logs/*.log).
 *
 * Consumed by the nuvemchat-bo SPA at /webmin/log-viewer. Guarded by
 * super-admin + bo.logs.view (see the admin route group). Reading is done
 * server-side and returned as JSON — the log files are never exposed directly.
 */
class LogViewerController extends Controller
{
    /** Cap on entries returned per request so a huge log never blows up memory. */
    private const MAX_ENTRIES = 2000;

    /**
     * List of log files + parsed entries for the selected file, filtered.
     */
    public function index(Request $request): JsonResponse
    {
        $files = $this->logFiles();
        $file = $this->resolveFile($request->query('file'));
        $level = strtolower(trim((string) $request->query('level', '')));
        $search = trim((string) $request->query('q', ''));

        $entries = $file ? $this->parse($this->path($file)) : [];

        $entries = array_values(array_filter($entries, function (array $e) use ($level, $search) {
            if ($level !== '' && $e['level'] !== $level) {
                return false;
            }
            if ($search !== '' && stripos($e['raw'], $search) === false) {
                return false;
            }
            return true;
        }));

        $total = count($entries);

        // Newest first, capped. Drop the bulky `raw` field from the response.
        $entries = array_map(
            fn (array $e) => ['timestamp' => $e['timestamp'], 'channel' => $e['channel'], 'level' => $e['level'], 'message' => $e['message'], 'stack' => $e['stack']],
            array_slice(array_reverse($entries), 0, self::MAX_ENTRIES),
        );

        return response()->json([
            'data' => [
                'files' => $files,
                'file' => $file,
                'entries' => $entries,
                'total' => $total,
                'shown' => count($entries),
                'levels' => ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
                'max_entries' => self::MAX_ENTRIES,
            ],
        ]);
    }

    /**
     * Stream the raw selected log file for download.
     */
    public function download(Request $request): BinaryFileResponse
    {
        $file = $this->resolveFile($request->query('file'));
        abort_if($file === null, 404, 'No log file found');

        return response()->download($this->path($file));
    }

    /**
     * @return array<int, string> log file basenames, newest-looking first
     */
    private function logFiles(): array
    {
        $paths = glob(storage_path('logs') . '/*.log') ?: [];

        return collect($paths)
            ->map(fn (string $p) => basename($p))
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * Resolve a requested file name against the whitelist of existing log files
     * (guards against path traversal). Falls back to the first file.
     */
    private function resolveFile(?string $name): ?string
    {
        $files = $this->logFiles();
        if (empty($files)) {
            return null;
        }

        return ($name !== null && in_array($name, $files, true)) ? $name : $files[0];
    }

    private function path(string $file): string
    {
        return storage_path('logs') . '/' . $file;
    }

    /**
     * Parse a Laravel log file into structured entries. Entries begin with a
     * `[timestamp] channel.LEVEL:` header; everything up to the next such header
     * (including multi-line stack traces) belongs to that entry.
     *
     * @return array<int, array{timestamp:string,channel:string,level:string,message:string,stack:string,raw:string}>
     */
    private function parse(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return [];
        }

        $pattern = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:\d{2})?)\]\s+([\w.\-]+)\.(\w+):/m';

        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
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
