<?php

namespace App\Services\Catalog;

use Illuminate\Validation\ValidationException;

/**
 * The first sheet of a CSV or XLSX file, as rows of strings.
 *
 * Written here instead of pulling in a spreadsheet library: an XLSX is a zip of
 * XML, and all an import needs from it is the cell text of one sheet. The zip
 * and xmlreader extensions this uses are in the production image (checked
 * 28 Sep 2026); where they are not, an XLSX is refused with a sentence that
 * says to save as CSV instead, rather than failing somewhere deeper.
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 5000;

    /**
     * @return list<list<string>> header row first
     */
    public static function read(string $path, string $extension): array
    {
        $rows = match (strtolower($extension)) {
            'xlsx' => self::xlsx($path),
            'csv', 'txt' => self::csv($path),
            default => throw ValidationException::withMessages([
                'file' => __('Send a .csv or .xlsx file.'),
            ]),
        };

        // Rows that are entirely blank are how spreadsheets end; they are not
        // products, and counting them would report "skipped" for nothing.
        $rows = array_values(array_filter($rows, fn (array $row) => implode('', array_map('trim', $row)) !== ''));

        if (count($rows) > self::MAX_ROWS + 1) {
            throw ValidationException::withMessages([
                'file' => __('The file has more than :max rows. Split it into smaller files.', ['max' => self::MAX_ROWS]),
            ]);
        }

        return $rows;
    }

    /** @return list<list<string>> */
    private static function csv(string $path): array
    {
        $content = (string) file_get_contents($path);

        // Excel in pt-BR saves CSV as Windows-1252 with ";" — read both
        // encodings and both separators, or half the files arrive as mojibake
        // in a single column.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($cell) => trim((string) $cell), $row);

            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> */
    private static function xlsx(string $path): array
    {
        if (! class_exists(\ZipArchive::class) || ! class_exists(\XMLReader::class)) {
            throw ValidationException::withMessages([
                'file' => __('This server cannot read .xlsx files. Save the spreadsheet as CSV and send it again.'),
            ]);
        }

        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages([
                'file' => __('The file could not be opened as a spreadsheet.'),
            ]);
        }

        try {
            $shared = self::sharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
            $sheet = $zip->getFromName(self::firstSheetPath($zip));

            if ($sheet === false) {
                throw ValidationException::withMessages([
                    'file' => __('The spreadsheet has no sheet to read.'),
                ]);
            }

            return self::sheetRows($sheet, $shared);
        } finally {
            $zip->close();
        }
    }

    /** The first sheet in workbook order — not necessarily sheet1.xml. */
    private static function firstSheetPath(\ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook && $rels
            && preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $sheet)
            && preg_match('/<Relationship\b[^>]*\bId="'.preg_quote($sheet[1], '/').'"[^>]*\bTarget="([^"]+)"/', $rels, $target)) {
            $path = ltrim($target[1], '/');

            return str_starts_with($path, 'xl/') ? $path : 'xl/'.$path;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** @return list<string> */
    private static function sharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $reader = new \XMLReader;
        $reader->XML($xml, null, LIBXML_NONET);

        $strings = [];
        $current = null;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                $current = '';
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 't' && $current !== null) {
                $current .= $reader->readString();
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = (string) $current;
                $current = null;
            }
        }

        $reader->close();

        return $strings;
    }

    /**
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private static function sheetRows(string $xml, array $shared): array
    {
        $reader = new \XMLReader;
        $reader->XML($xml, null, LIBXML_NONET);

        $rows = [];
        $row = null;
        $cellRef = null;
        $cellType = null;
        $value = '';

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                switch ($reader->localName) {
                    case 'row':
                        $row = [];
                        break;
                    case 'c':
                        $cellRef = (string) $reader->getAttribute('r');
                        $cellType = $reader->getAttribute('t');
                        $value = '';
                        break;
                    case 'v':
                        $value = $reader->readString();
                        break;
                    case 't':
                        // Inline strings keep their text in <is><t>.
                        if ($cellType === 'inlineStr') {
                            $value .= $reader->readString();
                        }
                        break;
                }
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT) {
                if ($reader->localName === 'c' && $row !== null) {
                    $text = $cellType === 's' ? ($shared[(int) $value] ?? '') : $value;
                    $row[self::columnIndex($cellRef)] = trim((string) $text);
                } elseif ($reader->localName === 'row' && $row !== null) {
                    // Cells are sparse: fill the gaps so column N stays column N.
                    $max = $row === [] ? -1 : max(array_keys($row));
                    $dense = [];
                    for ($i = 0; $i <= $max; $i++) {
                        $dense[] = $row[$i] ?? '';
                    }
                    $rows[] = $dense;
                    $row = null;

                    if (count($rows) > self::MAX_ROWS + 1) {
                        break;
                    }
                }
            }
        }

        $reader->close();

        return $rows;
    }

    /** "C12" → 2. */
    private static function columnIndex(?string $ref): int
    {
        if (! $ref || ! preg_match('/^([A-Z]+)/', $ref, $m)) {
            return 0;
        }

        $index = 0;
        foreach (str_split($m[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
