<?php

namespace App\Services\Crm\Automation;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The workspace's own records a definition may name — anything else is
 * another workspace's, or gone.

 */
final class AutomationReferences
{
    public function __construct(private int $tenantId) {}

    public function user(mixed $id, string $path): int
    {
        return $this->ids('users', [$id], $path)[0];
    }

    /** @return list<int> */
    public function users(array $ids, string $path): array
    {
        return $ids === [] ? [] : $this->ids('users', $ids, $path);
    }

    /** @return list<int> */
    public function tags(array $ids, string $path): array
    {
        return $this->ids('tags', $ids, $path);
    }

    public function flow(mixed $id, string $path): int
    {
        return $this->ids('flows', [$id], $path)[0];
    }

    public function pipeline(mixed $id, string $path): int
    {
        return $this->ids('crm_pipelines', [$id], $path)[0];
    }

    /** @return list<int> */
    public function stages(array $ids, string $path): array
    {
        return $this->ids('crm_pipeline_stages', $ids, $path);
    }

    /** @return list<int> */
    private function ids(string $table, array $ids, string $path): array
    {
        $given = array_values(array_unique(array_map('strval', array_filter($ids, fn ($v) => $v !== null && $v !== ''))));
        $clean = array_values(array_unique(array_map('intval', array_filter($given, 'is_numeric'))));

        if ($clean === [] || count($clean) !== count($given)
            || DB::table($table)->where('tenant_id', $this->tenantId)->whereIn('id', $clean)->count() !== count($clean)) {
            throw ValidationException::withMessages([$path => __('crm.automations.not_found')]);
        }

        return $clean;
    }
}
