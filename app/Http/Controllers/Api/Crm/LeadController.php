<?php

namespace App\Http\Controllers\Api\Crm;

use App\Enums\Crm\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\CrmPresenter;
use App\Models\CrmActivity;
use App\Models\CrmTask;
use App\Models\Lead;
use App\Services\Crm\LeadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** CRM › Leads. */
class LeadController extends Controller
{
    public function __construct(
        private readonly LeadService $leads,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(LeadStatus::values())],
            'owner' => ['nullable', 'string', 'max:20'],
            'customer_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        $page = Lead::query()
            ->visibleTo($user)
            ->with(['customer:id,name,status', 'owner:id,name,avatar_path'])
            ->when($data['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.$search.'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $like)->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like)));
            })
            ->when($data['status'] ?? null, fn (Builder $q, array $status) => $q->whereIn('status', $status))
            ->when($data['customer_id'] ?? null, fn (Builder $q, int $customer) => $q->where('customer_id', $customer))
            ->when($data['owner'] ?? null, function (Builder $q, string $owner) use ($user) {
                match ($owner) {
                    'none' => $q->whereNull('owner_user_id'),
                    'me' => $q->where('owner_user_id', $user->id),
                    default => $q->where('owner_user_id', (int) $owner),
                };
            })
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Lead $lead) => CrmPresenter::lead($lead))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $lead = $this->visible($request, $id);
        $lead->load(['customer', 'owner']);

        return response()->json([
            'data' => CrmPresenter::lead($lead),
            'activities' => CrmActivity::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)
                ->with('user:id,name')->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get()
                ->map(fn ($activity) => CrmPresenter::activity($activity))->values(),
            'tasks' => CrmTask::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)
                ->with(['assignee:id,name,avatar_path', 'creator:id,name'])->orderByRaw('completed_at IS NOT NULL')->orderBy('due_at')->get()
                ->map(fn ($task) => CrmPresenter::task($task))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $lead = $this->leads->create($user, $this->leads->validate($user->tenant_id, $request->all(), creating: true));

        return response()->json(['data' => CrmPresenter::lead($lead)], 201);
    }

    public function update(Request $request, string $id)
    {
        $lead = $this->visible($request, $id);
        $lead = $this->leads->update($lead, $request->user(), $this->leads->validate($lead->tenant_id, $request->all(), creating: false));

        return response()->json(['data' => CrmPresenter::lead($lead)]);
    }

    public function convert(Request $request, string $id)
    {
        $lead = $this->visible($request, $id);

        $deal = $this->leads->convert($lead, $request->user(), $request->only(['pipeline_id', 'stage_id', 'title', 'value_cents', 'expected_close_date']));

        return response()->json(['data' => CrmPresenter::deal($deal)], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $this->leads->delete($this->visible($request, $id), $request->user());

        return response()->json(['message' => __('crm.leads.deleted')]);
    }

    private function visible(Request $request, string $id): Lead
    {
        $lead = Lead::query()->visibleTo($request->user())->find($id);

        if (! $lead) {
            throw new HttpException(404, __('crm.leads.not_found'));
        }

        return $lead;
    }
}
