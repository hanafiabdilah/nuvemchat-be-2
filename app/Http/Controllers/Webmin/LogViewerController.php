<?php

namespace App\Http\Controllers\Webmin;

use App\Http\Controllers\Controller;
use App\Support\LogFile;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Minimal Laravel log viewer for the /webmin area.
 *
 * Intentionally unlinked from any navigation — reached only by typing the URL
 * (/webmin/log-viewer). Reads storage/logs/*.log, parses entries and renders a
 * self-contained Blade page. Guarded by the web `auth` middleware (see routes).
 *
 * Reading is bounded to the tail of the file; see App\Support\LogFile for why.
 */
class LogViewerController extends Controller
{
    /** Cap on entries rendered per request so a huge log never blows up memory/DOM. */
    private const MAX_ENTRIES = 1000;

    public function index(Request $request)
    {
        $files = LogFile::files();
        $file = LogFile::resolve($request->query('file'));
        $level = strtolower(trim((string) $request->query('level', '')));
        $search = trim((string) $request->query('q', ''));

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

        // Newest first, capped.
        $entries = array_slice(array_reverse($entries), 0, self::MAX_ENTRIES);

        return view('webmin.log-viewer', [
            'files' => $files,
            'file' => $file,
            'level' => $level,
            'search' => $search,
            'entries' => $entries,
            'total' => $total,
            'shown' => count($entries),
            'truncated' => $read['truncated'],
            'levels' => ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
        ]);
    }

    /**
     * Stream the raw selected log file for download.
     */
    public function download(Request $request): BinaryFileResponse
    {
        $file = LogFile::resolve($request->query('file'));
        abort_if($file === null, 404, 'No log file found');

        return response()->download(LogFile::path($file));
    }
}
