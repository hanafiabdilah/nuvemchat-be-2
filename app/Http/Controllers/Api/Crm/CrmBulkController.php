<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\Crm\CrmBulk;
use App\Services\Crm\CustomerFilter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Bulk actions on the CRM lists. Rows are picked by id (at most 500), or —
 * for customers — "everyone matching these filters or this segment" (at most
 * 5000). Only rows the reader may see are ever touched, and each action asks
 * for the permission a single change would.
 */
class CrmBulkController extends Controller
{
    public function __construct(
        private readonly CrmBulk $bulk,
    ) {}

    public function customers(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(CrmBulk::CUSTOMER_ACTIONS)],
            'value' => ['nullable'],
            'ids' => ['required_without:filters', 'array', 'max:'.CrmBulk::MAX_IDS],
            'ids.*' => ['integer'],
            'filters' => ['required_without:ids', 'array'],
        ]);

        $user = $request->user();
        $this->authorizeAction($user, $data['action'] === 'delete' ? 'customers.delete' : 'customers.update');

        $query = isset($data['ids'])
            ? CustomerFilter::query($user)->whereIn('customers.id', $data['ids'])
            : CustomerFilter::query($user, validator($data['filters'], CustomerFilter::listRules())->validate());

        if ((clone $query)->count() > CrmBulk::MAX_MATCHING) {
            throw ValidationException::withMessages(['filters' => __('crm.bulk.too_many', ['max' => CrmBulk::MAX_MATCHING])]);
        }

        return response()->json($this->bulk->customers($user, $query->get(), $data['action'], $data['value'] ?? null));
    }

    public function leads(Request $request)
    {
        $data = $this->selection($request, CrmBulk::LEAD_ACTIONS);
        $user = $request->user();
        $this->authorizeAction($user, $data['action'] === 'delete' ? 'leads.delete' : 'leads.manage');

        $rows = Lead::query()->visibleTo($user)->whereIn('crm_leads.id', $data['ids'])->get();

        return response()->json($this->bulk->leads($user, $rows, $data['action'], $data['value'] ?? null));
    }

    public function deals(Request $request)
    {
        $data = $this->selection($request, CrmBulk::DEAL_ACTIONS, ['lost_reason' => ['nullable', 'string', 'max:255']]);
        $user = $request->user();
        $this->authorizeAction($user, $data['action'] === 'delete' ? 'deals.delete' : 'deals.manage');

        $rows = Deal::query()->visibleTo($user)->whereIn('crm_deals.id', $data['ids'])->get();

        return response()->json($this->bulk->deals($user, $rows, $data['action'], $data['value'] ?? null, $data['lost_reason'] ?? null));
    }

    public function tasks(Request $request)
    {
        $data = $this->selection($request, CrmBulk::TASK_ACTIONS);
        $user = $request->user();

        $rows = CrmTask::query()->visibleTo($user)->whereIn('crm_tasks.id', $data['ids'])->get();

        return response()->json($this->bulk->tasks($user, $rows, $data['action'], $data['value'] ?? null));
    }

    /** @return array<string, mixed> */
    private function selection(Request $request, array $actions, array $extra = []): array
    {
        return $request->validate([
            'action' => ['required', Rule::in($actions)],
            'value' => ['nullable'],
            'ids' => ['required', 'array', 'min:1', 'max:'.CrmBulk::MAX_IDS],
            'ids.*' => ['integer'],
            ...$extra,
        ]);
    }

    private function authorizeAction($user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw new HttpException(403, __('crm.bulk.forbidden'));
        }
    }
}
