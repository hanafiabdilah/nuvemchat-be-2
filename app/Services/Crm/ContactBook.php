<?php

namespace App\Services\Crm;

use Illuminate\Support\Str;

/**
 * An address book — a phone's or Google Contacts' export — read into the same
 * table a customer spreadsheet arrives as, so the import's column mapping,
 * matching and report work on it unchanged.
 *
 * Three shapes are recognised: a vCard file (.vcf: 2.1 from Android, 3.0 from
 * Google and iPhone, 4.0), and the two CSVs Google Contacts' export dialog
 * offers ("Google CSV" in its current and legacy headers, and "Outlook CSV").
 * The CSVs need their own reading even though they are spreadsheets: their
 * columns come in pairs ("Phone 1 - Label", "Phone 1 - Value") and the name is
 * split in three, so guessing their columns the way a spreadsheet's are guessed
 * would import the word "Mobile" as everyone's phone and only first names.
 *
 * One person has one phone on a customer, but an address book holds several
 * per contact. The one kept is the preferred one, then a mobile, then the
 * first; the rest go to an "Other phones" column the person can map to a
 * custom field. Android keeps the pre-ninth-digit spelling of a Brazilian
 * mobile next to the current one ("X-Antigo", "X-Versão antiga") — that is the
 * same number, so it never wins and is not listed twice.
 *
 * Entries with no usable phone and no e-mail are left out and counted: in an
 * address book those are emergency numbers, carrier short codes (*144, 190)
 * and names someone never finished saving, and none of them is a customer.
 */
final class ContactBook
{
    public const VCARD = 'vcard';

    public const GOOGLE = 'google';

    public const OUTLOOK = 'outlook';

    /** Output columns, in order. Only the ones with a value somewhere are kept. */
    private const COLUMNS = ['name', 'phone', 'other_phones', 'email', 'company', 'job_title', 'birthday', 'address', 'notes'];

    private const MAX_FIELD_LENGTH = 2000;

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function __construct(public readonly string $format, private readonly array $entries, public readonly int $ignored) {}

    /** Whether the bytes are a vCard file, whatever the file was called. */
    public static function looksLikeVcard(string $content): bool
    {
        $head = ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', substr($content, 0, 512)));

        return stripos($head, 'BEGIN:VCARD') === 0;
    }

    public static function fromVcard(string $content): self
    {
        $entries = [];
        $ignored = 0;

        foreach (self::vcards($content) as $card) {
            $entry = self::vcardEntry($card);

            if ($entry === null) {
                continue;
            }

            if ($entry['phones'] === [] && $entry['email'] === null) {
                $ignored++;

                continue;
            }

            $entries[] = $entry;
        }

        return new self(self::VCARD, $entries, $ignored);
    }

    /**
     * The CSV of Google Contacts' export, or null when the header row is not
     * one of its shapes (then it is an ordinary spreadsheet).
     *
     * @param  list<list<string>>  $rows  header row first
     */
    public static function fromExportRows(array $rows): ?self
    {
        if ($rows === []) {
            return null;
        }

        $headers = [];
        foreach ($rows[0] as $index => $header) {
            $headers[mb_strtolower(trim((string) $header))] ??= $index;
        }

        $paired = preg_grep('/^(phone|e-mail) \d+ - value$/', array_keys($headers)) !== [];
        $format = match (true) {
            $paired && (isset($headers['first name']) || isset($headers['given name'])) => self::GOOGLE,
            isset($headers['first name'], $headers['last name']) && (isset($headers['mobile phone']) || isset($headers['e-mail address'])) => self::OUTLOOK,
            default => null,
        };

        if ($format === null) {
            return null;
        }

        $entries = [];
        $ignored = 0;

        foreach (array_slice($rows, 1) as $row) {
            $cell = fn (string $header) => trim((string) ($row[$headers[$header] ?? -1] ?? ''));
            $entry = $format === self::GOOGLE ? self::googleEntry($cell, $headers) : self::outlookEntry($cell);

            if ($entry === null) {
                continue;
            }

            if ($entry['phones'] === [] && $entry['email'] === null) {
                $ignored++;

                continue;
            }

            $entries[] = $entry;
        }

        return new self($format, $entries, $ignored);
    }

    /**
     * The entries as a spreadsheet: a header row in the reader's language,
     * then one row per contact.
     *
     * @return list<list<string>>
     */
    public function table(): array
    {
        $columns = array_values(array_filter(
            self::COLUMNS,
            fn (string $column) => $column === 'name' || collect($this->entries)->contains(fn ($entry) => filled(self::cell($entry, $column))),
        ));

        $rows = [array_map(fn (string $column) => __("crm.import.columns.{$column}"), $columns)];

        foreach ($this->entries as $entry) {
            $rows[] = array_map(fn (string $column) => self::cell($entry, $column), $columns);
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $entry */
    private static function cell(array $entry, string $column): string
    {
        return match ($column) {
            'phone' => $entry['phones'][0] ?? '',
            'other_phones' => implode(', ', array_slice($entry['phones'], 1)),
            default => (string) ($entry[$column] ?? ''),
        };
    }

    // ------------------------------------------------------------------ vCard

    /**
     * Each card's properties, unfolded and decoded.
     *
     * @return list<list<array{name: string, types: list<string>, values: list<string>}>>
     */
    private static function vcards(string $content): array
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $raw = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $lines = [];

        for ($i = 0, $count = count($raw); $i < $count; $i++) {
            $line = $raw[$i];

            // vCard 3.0/4.0 folding (and 2.1 base64): a line that starts with a
            // space or a tab continues the one before it.
            if ($lines !== [] && $line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $lines[array_key_last($lines)] .= substr($line, 1);

                continue;
            }

            // vCard 2.1 quoted-printable: a trailing "=" is a soft line break,
            // and the next line — even an empty one — carries on the value.
            while (self::isQuotedPrintable($line) && str_ends_with($line, '=') && $i + 1 < $count) {
                $line = substr($line, 0, -1).$raw[++$i];
            }

            $lines[] = $line;
        }

        $cards = [];
        $card = null;

        foreach ($lines as $line) {
            $property = self::property($line);

            if ($property === null) {
                continue;
            }

            $value = strtoupper(trim($property['values'][0] ?? ''));

            if ($property['name'] === 'BEGIN' && $value === 'VCARD') {
                $card = [];
            } elseif ($property['name'] === 'END' && $value === 'VCARD') {
                if ($card !== null) {
                    $cards[] = $card;
                }

                $card = null;
            } elseif ($card !== null) {
                $card[] = $property;
            }
        }

        return $cards;
    }

    private static function isQuotedPrintable(string $line): bool
    {
        $colon = strpos($line, ':');

        return $colon !== false && stripos(substr($line, 0, $colon), 'QUOTED-PRINTABLE') !== false;
    }

    /**
     * `GROUP.NAME;PARAM;KEY=VALUE:value` → name, types and the value's
     * components (a structured value like N splits on ";"; the others stay one).
     *
     * @return array{name: string, types: list<string>, values: list<string>}|null
     */
    private static function property(string $line): ?array
    {
        if (trim($line) === '') {
            return null;
        }

        $length = strlen($line);
        $quoted = false;

        for ($colon = 0; $colon < $length; $colon++) {
            if ($line[$colon] === '"') {
                $quoted = ! $quoted;
            } elseif ($line[$colon] === ':' && ! $quoted) {
                break;
            }
        }

        if ($colon >= $length) {
            return null;
        }

        $head = self::split(substr($line, 0, $colon), ';');
        $name = strtoupper(trim((string) array_shift($head)));
        $name = str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : $name;

        // Photos, logos, sounds and keys are binary and never a column.
        if (in_array($name, ['PHOTO', 'LOGO', 'SOUND', 'KEY'], true)) {
            return null;
        }

        $types = [];
        $encoding = null;
        $charset = null;

        foreach ($head as $param) {
            [$key, $value] = str_contains($param, '=') ? explode('=', $param, 2) : [null, $param];
            $key = $key === null ? null : strtoupper(trim($key));
            $value = trim($value, " \t\"");

            if ($key === 'ENCODING') {
                $encoding = strtoupper($value);
            } elseif ($key === 'CHARSET') {
                $charset = $value;
            } elseif ($key === 'TYPE') {
                array_push($types, ...array_map('mb_strtolower', explode(',', $value)));
            } elseif ($key === 'PREF') {
                $types[] = 'pref';
            } elseif ($key === null && in_array(strtoupper($value), ['QUOTED-PRINTABLE', 'BASE64', 'B', '8BIT', '7BIT'], true)) {
                // vCard 2.1 writes the encoding bare: TEL;CELL;QUOTED-PRINTABLE
                $encoding = strtoupper($value);
            } elseif ($key === null) {
                // …and the types bare too: TEL;CELL;PREF, TEL;X-Celular
                $types[] = mb_strtolower($value);
            }
        }

        $raw = substr($line, $colon + 1);
        $structured = in_array($name, ['N', 'ADR', 'ORG'], true);
        $values = array_map(
            fn (string $part) => self::unescape(self::decode($part, $encoding, $charset)),
            $structured ? self::split($raw, ';') : [$raw],
        );

        return ['name' => $name, 'types' => $types, 'values' => $values];
    }

    /** Split on a separator that is not escaped with "\" nor inside quotes. */
    private static function split(string $text, string $separator): array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $current .= $char.$text[++$i];
            } elseif ($char === '"') {
                $quoted = ! $quoted;
                $current .= $char;
            } elseif ($char === $separator && ! $quoted) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        $parts[] = $current;

        return $parts;
    }

    private static function decode(string $value, ?string $encoding, ?string $charset): string
    {
        if ($encoding === 'QUOTED-PRINTABLE') {
            $value = quoted_printable_decode($value);
        }

        if ($charset !== null && strtoupper($charset) !== 'UTF-8' && in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true)) {
            $value = (string) mb_convert_encoding($value, 'UTF-8', $charset);
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return $value;
    }

    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\(.)/s', fn ($m) => in_array($m[1], ['n', 'N'], true) ? "\n" : $m[1], $value);
    }

    /**
     * @param  list<array{name: string, types: list<string>, values: list<string>}>  $card
     * @return array<string, mixed>|null null for a card with nothing in it
     */
    private static function vcardEntry(array $card): ?array
    {
        $first = function (string $name) use ($card): ?array {
            $matches = array_values(array_filter($card, fn ($p) => $p['name'] === $name));
            usort($matches, fn ($a, $b) => (int) in_array('pref', $b['types'], true) <=> (int) in_array('pref', $a['types'], true));

            return $matches[0] ?? null;
        };
        $text = fn (?array $property, int $component = 0) => self::clean($property['values'][$component] ?? null);

        $phones = [];
        $emails = [];

        foreach ($card as $property) {
            $value = trim(implode(';', $property['values']));

            if ($property['name'] === 'TEL') {
                $phones[] = ['value' => (string) preg_replace('/^tel:/i', '', $value), 'types' => $property['types']];
            } elseif ($property['name'] === 'EMAIL') {
                $emails[] = ['value' => (string) preg_replace('/^mailto:/i', '', $value), 'types' => $property['types']];
            }
        }

        $n = $first('N');
        $nameFromParts = self::clean(implode(' ', array_filter([
            $text($n, 1), // given
            $text($n, 2), // additional
            $text($n, 0), // family
        ])));

        $adr = $first('ADR');
        $address = $adr === null ? null : self::clean(implode(', ', array_filter(array_map(
            fn ($i) => self::clean($adr['values'][$i] ?? null),
            [2, 1, 3, 4, 5, 6], // street, extended, locality, region, postal code, country
        ))));

        return self::entry(
            name: self::clean($text($first('FN'))) ?? $nameFromParts,
            phones: $phones,
            emails: $emails,
            company: $text($first('ORG')),
            jobTitle: $text($first('TITLE')),
            birthday: self::birthday($text($first('BDAY'))),
            address: $address,
            notes: $text($first('NOTE')),
        );
    }

    // -------------------------------------------------------- Google / Outlook

    /**
     * @param  callable(string): string  $cell
     * @param  array<string, int>  $headers
     * @return array<string, mixed>|null
     */
    private static function googleEntry(callable $cell, array $headers): ?array
    {
        $name = isset($headers['first name'])
            ? implode(' ', array_filter([$cell('first name'), $cell('middle name'), $cell('last name')]))
            : ($cell('name') ?: implode(' ', array_filter([$cell('given name'), $cell('additional name'), $cell('family name')])));

        $pairs = function (string $kind) use ($cell, $headers): array {
            $out = [];

            foreach (array_keys($headers) as $header) {
                if (! preg_match('/^'.$kind.' (\d+) - value$/', $header, $m)) {
                    continue;
                }

                $label = $cell("{$kind} {$m[1]} - label") ?: $cell("{$kind} {$m[1]} - type");
                $types = [mb_strtolower($label)];

                // Google marks the primary value "* Mobile".
                if (str_starts_with($label, '*')) {
                    $types[] = 'pref';
                }

                foreach (explode(':::', $cell($header)) as $value) {
                    if (trim($value) !== '') {
                        $out[] = ['value' => trim($value), 'types' => $types];
                    }
                }
            }

            return $out;
        };

        return self::entry(
            name: self::clean($name) ?? self::clean($cell('nickname')) ?? self::clean($cell('file as')),
            phones: $pairs('phone'),
            emails: $pairs('e-mail'),
            company: self::clean($cell('organization name') ?: $cell('organization 1 - name')),
            jobTitle: self::clean($cell('organization title') ?: $cell('organization 1 - title')),
            birthday: self::birthday($cell('birthday')),
            address: self::clean(str_replace("\n", ', ', $cell('address 1 - formatted'))),
            notes: self::clean($cell('notes')),
        );
    }

    /**
     * @param  callable(string): string  $cell
     * @return array<string, mixed>|null
     */
    private static function outlookEntry(callable $cell): ?array
    {
        $phones = [];

        foreach ([
            'primary phone' => ['pref'], 'mobile phone' => ['cell'], 'home phone' => ['home'], 'business phone' => ['work'],
            'other phone' => ['other'], 'home phone 2' => ['home'], 'business phone 2' => ['work'],
            'company main phone' => ['work'], 'car phone' => ['cell'],
        ] as $column => $types) {
            if ($cell($column) !== '') {
                $phones[] = ['value' => $cell($column), 'types' => $types];
            }
        }

        $emails = array_map(
            fn ($column) => ['value' => $cell($column), 'types' => []],
            array_values(array_filter(['e-mail address', 'e-mail 2 address', 'e-mail 3 address'], fn ($column) => $cell($column) !== '')),
        );

        // Outlook writes "0/0/00" for a birthday nobody filled in.
        $birthday = $cell('birthday');

        return self::entry(
            name: self::clean(implode(' ', array_filter([$cell('first name'), $cell('middle name'), $cell('last name'), $cell('suffix')]))),
            phones: $phones,
            emails: $emails,
            company: self::clean($cell('company')),
            jobTitle: self::clean($cell('job title')),
            birthday: in_array($birthday, ['0/0/00', '00/00/0000'], true) ? null : self::birthday($birthday),
            address: null,
            notes: self::clean($cell('notes')),
        );
    }

    // ------------------------------------------------------------------ shared

    /**
     * @param  list<array{value: string, types: list<string>}>  $phones
     * @param  list<array{value: string, types: list<string>}>  $emails
     * @return array<string, mixed>|null
     */
    private static function entry(?string $name, array $phones, array $emails, ?string $company, ?string $jobTitle, ?string $birthday, ?string $address, ?string $notes): ?array
    {
        if ($name === null && $phones === [] && $emails === [] && $company === null) {
            return null;
        }

        $phones = self::phones($phones);
        $email = self::email($emails);

        // Android often saves a business as both the name and the company;
        // the company would just repeat the person.
        if ($company !== null && $name !== null && mb_strtolower($company) === mb_strtolower($name)) {
            $company = null;
        }

        return [
            'name' => $name ?? $company ?? $email ?? ($phones[0] ?? null),
            'phones' => $phones,
            'email' => $email,
            'company' => $company,
            'job_title' => $jobTitle,
            'birthday' => $birthday,
            'address' => $address,
            'notes' => $notes,
        ];
    }

    /**
     * The usable numbers, best first, each person's number once.
     *
     * @param  list<array{value: string, types: list<string>}>  $phones
     * @return list<string>
     */
    private static function phones(array $phones): array
    {
        $ranked = [];

        foreach ($phones as $order => $phone) {
            $value = trim((string) preg_replace('/\s+/', ' ', $phone['value']));
            $digits = (string) preg_replace('/\D/', '', $value);

            // *144, **21*100#, 190: carrier codes and emergency numbers.
            if (preg_match('/[*#]/', $value) || strlen($digits) < 8 || strlen($digits) > 16) {
                continue;
            }

            $types = Str::lower(Str::ascii(implode(' ', $phone['types'])));

            if (preg_match('/fax|pager|bip/', $types)) {
                continue;
            }

            $score = (in_array('pref', $phone['types'], true) ? 10 : 0)
                + (preg_match('/cell|mobile|celular|\bcel\b|cel\.|movil|handphone|\bhp\b|whatsapp|iphone/', $types) ? 5 : 0)
                - (preg_match('/antig|\bold\b|versao|velho|\blama\b/', $types) ? 20 : 0);

            $ranked[] = ['value' => $value, 'digits' => $digits, 'score' => $score, 'order' => $order];
        }

        usort($ranked, fn ($a, $b) => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        $seen = [];
        $out = [];

        foreach ($ranked as $phone) {
            // The last eight digits: the same number with and without its
            // country code, trunk zero or Brazil's ninth digit.
            $key = substr($phone['digits'], -8);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $phone['value'];
            }
        }

        return $out;
    }

    /** @param  list<array{value: string, types: list<string>}>  $emails */
    private static function email(array $emails): ?string
    {
        usort($emails, fn ($a, $b) => (int) in_array('pref', $b['types'], true) <=> (int) in_array('pref', $a['types'], true));

        foreach ($emails as $email) {
            $value = trim($email['value']);

            if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                return $value;
            }
        }

        return null;
    }

    /** "1990-05-12", "19900512", "1990-05-12T00:00:00Z" → "1990-05-12"; anything else as written. */
    private static function birthday(?string $value): ?string
    {
        $value = self::clean($value);

        if ($value !== null && preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $value, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return $value;
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) preg_replace('/[ \t]+/', ' ', (string) $value));

        return $value === '' ? null : Str::limit($value, self::MAX_FIELD_LENGTH, '');
    }
}
