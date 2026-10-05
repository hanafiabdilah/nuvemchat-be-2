<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The first page of a PDF as a picture.
 *
 * Vision models are handed images, and a bank's "comprovante" is as often a
 * PDF as a screenshot. The page is rendered with poppler's `pdftoppm` and
 * published for the moment a model needs to fetch it; the caller deletes it
 * afterwards. Without the binary there is no preview, and the caller says so
 * rather than guessing.
 */
final class PdfPreview
{
    /**
     * @return array{path: string, url: string}|null a file on the outbound disk
     */
    public static function firstPage(string $pdfBytes): ?array
    {
        $binary = (string) config('media.pdftoppm_path', 'pdftoppm');
        $base = sys_get_temp_dir().'/pdf-preview-'.Str::random(16);

        try {
            file_put_contents("{$base}.pdf", $pdfBytes);

            $result = Process::timeout(30)->run([
                $binary, '-png', '-r', '110', '-f', '1', '-l', '1', '-singlefile', "{$base}.pdf", $base,
            ]);

            if (! $result->successful() || ! is_file("{$base}.png")) {
                Log::warning('PdfPreview: could not render the first page', [
                    'error' => Str::limit($result->errorOutput(), 300),
                ]);

                return null;
            }

            $path = 'pdf-previews/'.Str::random(24).'/page-1.png';
            MediaStorage::outbound()->put($path, file_get_contents("{$base}.png"));

            return ['path' => $path, 'url' => MediaStorage::outboundUrl($path)];
        } catch (\Throwable $th) {
            Log::warning('PdfPreview: could not render the first page', ['error' => $th->getMessage()]);

            return null;
        } finally {
            @unlink("{$base}.pdf");
            @unlink("{$base}.png");
        }
    }

    public static function discard(?array $preview): void
    {
        if ($preview !== null) {
            MediaStorage::outbound()->delete($preview['path']);
        }
    }
}
