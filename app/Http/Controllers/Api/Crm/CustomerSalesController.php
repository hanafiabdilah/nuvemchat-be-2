<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\CrmPresenter;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The sales side of one person, for Customer 360 and the inbox card: their
 * leads, deals and open tasks — each list only what the reader may see.
 */
class CustomerSalesController extends Controller
{
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $customer = Customer::query()->visibleTo($user)->find($id) ?? throw new HttpException(404, __('crm.customers.not_found'));

        $leads = $user->can('leads.view')
            ? Lead::query()->visibleTo($user)->where('customer_id', $customer->id)
                ->with(['owner:id,name,avatar_path'])
                ->orderByRaw("status IN ('converted', 'disqualified')")->orderByDesc('id')->limit(20)->get()
                ->map(fn (Lead $lead) => CrmPresenter::lead($lead))->values()
            : null;

        $deals = $user->can('deals.view')
            ? Deal::query()->visibleTo($user)->where('customer_id', $customer->id)
                ->with(['stage', 'pipeline:id,name', 'owner:id,name,avatar_path', 'company:id,name'])
                ->orderByRaw("status <> 'open'")->orderByDesc('id')->limit(20)->get()
                ->map(fn (Deal $deal) => CrmPresenter::deal($deal))->values()
            : null;

        $tasks = CrmTask::query()->visibleTo($user)->where('customer_id', $customer->id)->whereNull('completed_at')
            ->with(['assignee:id,name,avatar_path', 'creator:id,name', 'subject'])
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->limit(20)->get()
            ->map(fn (CrmTask $task) => CrmPresenter::task($task))->values();

        $open = Deal::query()->visibleTo($user)->where('customer_id', $customer->id);

        return response()->json([
            'leads' => $leads,
            'deals' => $deals,
            'tasks' => $tasks,
            'summary' => [
                'open_deals' => $user->can('deals.view') ? (clone $open)->where('status', 'open')->count() : null,
                'open_value_cents' => $user->can('deals.view') ? (int) (clone $open)->where('status', 'open')->sum('value_cents') : null,
                'won_value_cents' => $user->can('deals.view') ? (int) (clone $open)->where('status', 'won')->sum('value_cents') : null,
            ],
        ]);
    }
}
