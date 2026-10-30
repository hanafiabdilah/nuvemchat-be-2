<?php

namespace App\Services\Crm;

use App\Enums\Crm\CustomerStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Tag;
use App\Models\User;
use App\Services\Catalog\SpreadsheetReader;
use App\Support\PhoneVariants;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bringing a spreadsheet of customers into the CRM, in two steps like the
 * product import: `preview` reads the file and guesses each column from the
 * header words people use ("Nama", "No HP", "Perusahaan"…), `commit` imports
 * with the mapping the person confirmed.
 *
 * A row is matched to an existing customer by phone or e-mail — the same two
 * identities the automatic recognition trusts, never the name — and then
 * either filled in (only what is empty), overwritten, or left alone. New rows
 * become customers with source "import"; when that person later writes in from
 * the same phone or e-mail, they are recognised as this customer.
 *
 * Tags live on a customer's channel contacts, so they can only be put on
 * customers who already have one; anything that cannot be applied is reported
 * on the row, never silently dropped.
 */
final class CustomerImport
{
    public const MAX_ROWS = 2000;

    public const FIELDS = ['name', 'phone', 'email', 'company', 'job_title', 'status', 'source', 'owner', 'tags'];

    public const ON_MATCH = ['fill', 'overwrite', 'skip'];

    private const CACHE_MINUTES = 30;

    /** Header words, accent-free and lowercase: Portuguese, Indonesian and English. */
    private const HEADER_WORDS = [
        'phone' => ['hp', 'telepon', 'telp', 'phone', 'whatsapp', 'wa', 'nomor', 'no', 'mobile', 'ponsel', 'telefone', 'celular', 'fone', 'numero', 'zap'],
        'email' => ['email', 'mail', 'surel', 'e-mail'],
        'company' => ['perusahaan', 'company', 'organisasi', 'organization', 'kantor', 'instansi', 'empresa', 'organizacao'],
        'job_title' => ['jabatan', 'title', 'posisi', 'position', 'job', 'role', 'cargo', 'funcao'],
        'status' => ['status', 'situacao'],
        'source' => ['sumber', 'source', 'asal', 'channel', 'origem', 'fonte', 'canal'],
        'owner' => ['pic', 'owner', 'penanggung', 'sales', 'operator', 'agent', 'responsavel', 'vendedor', 'atendente'],
        'tags' => ['tag', 'tags', 'label', 'labels', 'kategori', 'etiqueta', 'etiquetas', 'categoria'],
        'name' => ['nama', 'name', 'pelanggan', 'customer', 'kontak', 'contact', 'nome', 'cliente', 'contato'],
    ];

    public function __construct(private CustomerService $customers) {}

    /**
     * @return array{token: string, headers: list<string>, mapping: array<string, int|null>, custom_fields: list<array{key: string, label: string}>, rows: list<list<string>>, total_rows: int}
     */
    public function preview(int $tenantId, UploadedFile $file): array
    {
        $rows = SpreadsheetReader::read($file->getRealPath(), $file->getClientOriginalExtension());

        if (count($rows) < 2) {
            throw ValidationException::withMessages(['file' => __('crm.import.empty')]);
        }

        if (count($rows) > self::MAX_ROWS + 1) {
            throw ValidationException::withMessages(['file' => __('crm.import.too_many', ['max' => self::MAX_ROWS])]);
        }

        $headers = array_map(fn ($cell) => (string) $cell, array_shift($rows));
        $definitions = CustomFields::definitions($tenantId, 'customer');
        $mapping = self::guessMapping($headers, $definitions->map(fn ($d) => ['key' => $d->key, 'label' => $d->label])->all());
        $token = (string) Str::uuid();

        Cache::put($this->cacheKey($tenantId, $token), ['rows' => $rows], now()->addMinutes(self::CACHE_MINUTES));

        return [
            'token' => $token,
            'headers' => $headers,
            'mapping' => $mapping,
            'custom_fields' => $definitions->map(fn ($d) => ['key' => $d->key, 'label' => $d->label])->values()->all(),
            'rows' => array_slice($rows, 0, 10),
            'total_rows' => count($rows),
        ];
    }

    /**
     * @param  array<string, int|null>  $mapping  field (or "custom.{key}") => column index
     * @return array{created: int, updated: int, unchanged: int, skipped: int, rows: list<array{row: int, status: string, message: string|null}>}
     */
    public function commit(User $actor, string $token, array $mapping, string $onMatch): array
    {
        $cached = Cache::get($this->cacheKey($actor->tenant_id, $token));

        if (! is_array($cached)) {
            throw ValidationException::withMessages(['token' => __('crm.import.expired')]);
        }

        if (($mapping['name'] ?? null) === null) {
            throw ValidationException::withMessages(['mapping.name' => __('crm.import.name_required')]);
        }

        if (! in_array($onMatch, self::ON_MATCH, true)) {
            throw ValidationException::withMessages(['on_match' => __('crm.import.invalid')]);
        }

        $definitions = CustomFields::definitions($actor->tenant_id, 'customer')->keyBy('key');
        $context = [
            'companies' => Company::query()->where('tenant_id', $actor->tenant_id)->get(['id', 'name'])->keyBy(fn ($c) => mb_strtolower($c->name)),
            'users' => User::query()->where('tenant_id', $actor->tenant_id)->get(['id', 'name', 'email']),
            'tags' => Tag::query()->where('tenant_id', $actor->tenant_id)->get(['id', 'name'])->keyBy(fn ($t) => mb_strtolower($t->name)),
        ];

        $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'rows' => []];

        foreach ($cached['rows'] as $index => $raw) {
            $line = $index + 2;

            try {
                [$status, $notes] = $this->importRow($actor, $this->mapRow($raw, $mapping), $onMatch, $definitions, $context);
                $report[$status]++;

                if ($notes !== [] && count($report['rows']) < 200) {
                    $report['rows'][] = ['row' => $line, 'status' => $status, 'message' => implode(' ', $notes)];
                }
            } catch (ValidationException $e) {
                $report['skipped']++;

                if (count($report['rows']) < 200) {
                    $report['rows'][] = ['row' => $line, 'status' => 'skipped', 'message' => (string) collect($e->errors())->flatten()->first()];
                }
            }
        }

        Cache::forget($this->cacheKey($actor->tenant_id, $token));

        return $report;
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array{0: string, 1: list<string>} status, notes
     */
    private function importRow(User $actor, array $row, string $onMatch, $definitions, array &$context): array
    {
        $notes = [];
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('crm.import.name_empty')]);
        }

        $fields = ['name' => Str::limit($name, 255, '')];

        foreach (['phone', 'email', 'job_title', 'source'] as $field) {
            if (filled($row[$field] ?? null)) {
                $fields[$field] = trim((string) $row[$field]);
            }
        }

        if (filled($row['status'] ?? null)) {
            $status = self::status((string) $row['status']);

            if ($status) {
                $fields['status'] = $status;
            } else {
                $notes[] = __('crm.import.unknown_status', ['value' => $row['status']]);
            }
        }

        if (filled($row['company'] ?? null)) {
            $fields['company_id'] = $this->company($actor, trim((string) $row['company']), $context);
        }

        if (filled($row['owner'] ?? null)) {
            $owner = $this->owner(trim((string) $row['owner']), $context);

            if ($owner) {
                $fields['owner_user_id'] = $owner;
            } else {
                $notes[] = __('crm.import.unknown_owner', ['value' => $row['owner']]);
            }
        }

        $custom = [];

        foreach ($definitions as $key => $definition) {
            $value = $row['custom.'.$key] ?? null;

            if (filled($value)) {
                $normalized = CustomFields::normalize($definition, trim((string) $value));

                if ($normalized !== null) {
                    $custom[$key] = $normalized;
                } else {
                    $notes[] = __('crm.import.invalid_field', ['field' => $definition->label]);
                }
            }
        }

        // Phone and e-mail checked the way a form would check them.
        try {
            $clean = $this->customers->validate($actor->tenant_id, array_intersect_key($fields, array_flip(['phone', 'email'])), creating: false);
            $fields = [...$fields, ...$clean];
        } catch (ValidationException $e) {
            $notes[] = (string) collect($e->errors())->flatten()->first();
            unset($fields['phone'], $fields['email']);
        }

        $existing = $this->match($actor->tenant_id, $fields['phone'] ?? null, $fields['email'] ?? null);

        if (! $existing) {
            $customer = $this->customers->create($actor, [
                ...$fields,
                'source' => $fields['source'] ?? 'import',
                'custom_fields' => $custom,
            ]);

            if (filled($row['tags'] ?? null)) {
                $notes[] = __('crm.import.tags_need_contact');
            }

            return ['created', $notes];
        }

        if ($onMatch === 'skip') {
            return ['unchanged', [__('crm.import.matched_skipped', ['name' => $existing->name])]];
        }

        $changes = $onMatch === 'overwrite'
            ? $fields
            : array_filter($fields, fn ($value, $key) => blank($existing->getAttribute($key)), ARRAY_FILTER_USE_BOTH);

        $customChanges = $onMatch === 'overwrite'
            ? $custom
            : array_filter($custom, fn ($value, $key) => blank($existing->custom_fields[$key] ?? null), ARRAY_FILTER_USE_BOTH);

        if ($customChanges !== []) {
            $changes['custom_fields'] = $customChanges;
        }

        // Matched on phone/e-mail: never rename someone to a typo of themselves
        // unless the person chose to overwrite.
        if ($onMatch !== 'overwrite') {
            unset($changes['name']);
        }

        $tagged = filled($row['tags'] ?? null) ? $this->applyTags($actor, $existing, (string) $row['tags'], $context, $notes) : false;

        if ($changes === []) {
            return [$tagged ? 'updated' : 'unchanged', $notes];
        }

        $this->customers->update($existing, $actor, $changes);

        return ['updated', $notes];
    }

    private function match(int $tenantId, ?string $phone, ?string $email): ?Customer
    {
        if (! $phone && ! $email) {
            return null;
        }

        return Customer::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($phone, $email) {
                if ($phone) {
                    $q->orWhereIn('phone', PhoneVariants::of($phone));
                }

                if ($email) {
                    $q->orWhere('email', $email);
                }
            })
            ->orderBy('id')
            ->first();
    }

    private function company(User $actor, string $name, array &$context): int
    {
        $key = mb_strtolower($name);

        if (! isset($context['companies'][$key])) {
            $context['companies'][$key] = Company::create(['tenant_id' => $actor->tenant_id, 'name' => Str::limit($name, 255, '')]);
        }

        return $context['companies'][$key]->id;
    }

    private function owner(string $value, array $context): ?int
    {
        $needle = mb_strtolower($value);

        return $context['users']->first(fn (User $u) => mb_strtolower((string) $u->email) === $needle || mb_strtolower($u->name) === $needle)?->id;
    }

    /** @param  list<string>  $notes */
    private function applyTags(User $actor, Customer $customer, string $value, array $context, array &$notes): bool
    {
        if (! $customer->contacts()->exists()) {
            $notes[] = __('crm.import.tags_need_contact');

            return false;
        }

        $ids = [];

        foreach (array_filter(array_map('trim', preg_split('/[,;|]/', $value) ?: [])) as $name) {
            $tag = $context['tags'][mb_strtolower($name)] ?? null;

            if ($tag) {
                $ids[] = $tag->id;
            } else {
                $notes[] = __('crm.import.unknown_tag', ['value' => $name]);
            }
        }

        if ($ids === []) {
            return false;
        }

        $current = \DB::table('contact_tags')->join('contacts', 'contacts.id', '=', 'contact_tags.contact_id')
            ->where('contacts.customer_id', $customer->id)->distinct()->pluck('contact_tags.tag_id')->map(fn ($id) => (int) $id)->all();
        $next = array_values(array_unique([...$current, ...$ids]));

        if (count($next) === count($current)) {
            return false;
        }

        $this->customers->syncTags($customer, $actor, $next);

        return true;
    }

    private static function status(string $value): ?string
    {
        $value = Str::lower(Str::ascii(trim($value)));
        $words = [
            CustomerStatus::New->value => ['new', 'baru', 'novo', 'nova'],
            CustomerStatus::Prospect->value => ['prospect', 'prospek', 'calon', 'prospecto', 'lead'],
            CustomerStatus::Customer->value => ['customer', 'pelanggan', 'client', 'klien', 'cliente'],
            CustomerStatus::Inactive->value => ['inactive', 'tidak aktif', 'nonaktif', 'non aktif', 'inativo', 'inativa'],
        ];

        foreach ($words as $status => $list) {
            if (in_array($value, $list, true)) {
                return $status;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array{key: string, label: string}>  $customFields
     * @return array<string, int|null>
     */
    public static function guessMapping(array $headers, array $customFields = []): array
    {
        $normalize = fn ($text) => trim((string) preg_replace('/[^a-z0-9 ]+/', ' ', Str::lower(Str::ascii((string) $text))));
        $normalized = array_map($normalize, $headers);
        $mapping = array_fill_keys(self::FIELDS, null);
        $taken = [];

        // Custom fields first, by their exact label or key: a column named
        // after the workspace's own field is that field, not a guess.
        foreach ($customFields as $field) {
            $mapping['custom.'.$field['key']] = null;

            foreach ($normalized as $index => $header) {
                if (! isset($taken[$index]) && $header !== '' && in_array($header, [$normalize($field['label']), $normalize($field['key'])], true)) {
                    $mapping['custom.'.$field['key']] = $index;
                    $taken[$index] = true;
                    break;
                }
            }
        }

        foreach (self::HEADER_WORDS as $field => $words) {
            foreach ($normalized as $index => $header) {
                if (isset($taken[$index]) || $header === '') {
                    continue;
                }

                if (array_intersect($words, explode(' ', $header)) !== [] || in_array($header, $words, true)) {
                    $mapping[$field] = $index;
                    $taken[$index] = true;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int|null>  $mapping
     * @return array<string, string|null>
     */
    private function mapRow(array $row, array $mapping): array
    {
        $out = [];

        foreach ($mapping as $field => $index) {
            $out[$field] = $index === null ? null : ($row[(int) $index] ?? null);
        }

        return $out;
    }

    private function cacheKey(int $tenantId, string $token): string
    {
        return "crm:customer-import:{$tenantId}:{$token}";
    }
}
