<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\LogFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Back Office API for reading the backend's Laravel logs (storage/logs/*.log).
 *
 * Consumed by the nuvemchat-bo SPA at /webmin/log-viewer. Guarded by
 * super-admin + bo.logs.view (see the admin route group). Reading is done
 * server-side and returned as JSON — the log files are never exposed directly.
 *
 * ⚠️ These files are the least redacted surface on the platform: webhook
 * bodies, upstream errors quoted verbatim, customer phone numbers. Reading
 * them is legitimate and necessary, and it is also the one Back Office action
 * that could quietly hand somebody a workspace's contents — so downloads are
 * recorded in the audit trail alongside impersonation, for the same reason.
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
        $files = LogFile::files();
        $file = LogFile::resolve($request->query('file'));
        $level = strtolower(trim((string) $request->query('level', '')));
        $search = trim((string) $request->query('q', ''));

        // Only the tail is read — see LogFile. Everything outside the window
        // would have been discarded by the cap below anyway.
        $read = $file ? LogFile::read(LogFile::path($file)) : ['entries' => [], 'size' => 0, 'scanned' => 0, 'truncated' => false];

        $entries = array_values(array_filter($read['entries'], function (array $e) use ($level, $search) {
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
                // So the page can say "the older part of this file was not
                // read" rather than let an operator conclude it is not there.
                'truncated' => $read['truncated'],
                'scanned_bytes' => $read['scanned'],
                'file_bytes' => $read['size'],
            ],
        ]);
    }

    /**
     * Stream the raw selected log file for download.
     */
    public function download(Request $request): BinaryFileResponse
    {
        $file = LogFile::resolve($request->query('file'));
        abort_if($file === null, 404, 'No log file found');

        AuditLog::record(
            'logs.downloaded',
            "Downloaded the log file {$file}",
            ['file' => $file],
        );

        return response()->download(LogFile::path($file));
    }
}
