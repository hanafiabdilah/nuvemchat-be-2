<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\CrmPresenter;
use App\Models\CrmActivity;
use App\Models\CrmPipeline;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Services\Crm\CrmPipelines;
use App\Services\Crm\DealService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * CRM › Deals: the board (one pipeline, its stages and the deals in them),
 * a list, and one deal.
 */
class DealController extends Controller
{
    /** Cards per column on the board — a stage with more says so. */
    private const BOARD_LIMIT = 200;

    public function __construct(
        private readonly DealService $deals,
    ) {}

    /**
     * The board: every stage of the pipeline with its deals in order, and per
     * stage the count, the total and the weighted total (value × probability)
     * — what the pipeline is worth, and what it is likely worth.
     */
    public function board(Request $request)
    {
        $data = $request->validate([
            'pipeline_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'owner' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();
        $pipelines = CrmPipelines::forTenant($user->tenant_id);
        $pipeline = isset($data['pipeline_id'])
            ? $pipelines->firstWhere('id', (int) $data['pipeline_id'])
            : ($pipelines->firstWhere('is_default', true) ?? $pipelines->first());

        if (! $pipeline) {
            throw new HttpException(404, __('crm.pipelines.not_found'));
        }

        $base = $this->filtered(Deal::query()->visibleTo($user)->where('pipeline_id', $pipeline->id), $data, $user);

        $columns = $pipeline->stages->map(function ($stage) use ($base) {
            $query = (clone $base)->where('stage_id', $stage->id);
            $count = (clone $query)->count();
            $total = (int) (clone $query)->sum('value_cents');

            return CrmPresenter::stage($stage) + [
                'count' => $count,
                'total_cents' => $total,
                'weighted_cents' => (int) round($total * $stage->probability / 100),
                'truncated' => $count > self::BOARD_LIMIT,
                'deals' => $query->with(['customer:id,name,status', 'owner:id,name,avatar_path', 'company:id,name'])
                    ->orderBy('position')->orderBy('id')->limit(self::BOARD_LIMIT)->get()
                    ->map(fn (Deal $deal) => CrmPresenter::deal($deal))->values(),
            ];
        })->values();

        return response()->json([
            'pipeline' => CrmPresenter::pipeline($pipeline),
            'pipelines' => $pipelines->map(fn (CrmPipeline $p) => ['id' => $p->id, 'name' => $p->name, 'is_default' => (bool) $p->is_default])->values(),
            'columns' => $columns,
        ]);
    }

    /** A list of deals — for the customer page, a company, or a search. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['open', 'won', 'lost'])],
            'owner' => ['nullable', 'string', 'max:20'],
            'customer_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
            'pipeline_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = $this->filtered(Deal::query()->visibleTo($user), $data, $user)
            ->when($data['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($data['customer_id'] ?? null, fn (Builder $q, int $customer) => $q->where('customer_id', $customer))
            ->when($data['company_id'] ?? null, fn (Builder $q, int $company) => $q->where('company_id', $company))
            ->when($data['pipeline_id'] ?? null, fn (Builder $q, int $pipeline) => $q->where('pipeline_id', $pipeline))
            ->with(['stage', 'pipeline:id,name', 'customer:id,name,status', 'owner:id,name,avatar_path', 'company:id,name'])
            ->orderByDesc('id');

        $page = $query->paginate($data['per_page'] ?? 50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Deal $deal) => CrmPresenter::deal($deal))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $deal = $this->visible($request, $id);
        $deal->load(['stage', 'pipeline.stages', 'customer', 'owner', 'company']);

        return response()->json([
            'data' => CrmPresenter::deal($deal),
            'stages' => $deal->pipeline ? $deal->pipeline->stages->map(fn ($stage) => CrmPresenter::stage($stage))->values() : [],
            'activities' => CrmActivity::query()->where('subject_type', 'deal')->where('subject_id', $deal->id)
                ->with('user:id,name')->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get()
                ->map(fn ($activity) => CrmPresenter::activity($activity))->values(),
            'tasks' => CrmTask::query()->where('subject_type', 'deal')->where('subject_id', $deal->id)
                ->with(['assignee:id,name,avatar_path', 'creator:id,name'])->orderByRaw('completed_at IS NOT NULL')->orderBy('due_at')->get()
                ->map(fn ($task) => CrmPresenter::task($task))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $deal = $this->deals->create($user, $this->deals->validate($user->tenant_id, $request->all(), creating: true));

        return response()->json(['data' => CrmPresenter::deal($deal)], 201);
    }

    public function update(Request $request, string $id)
    {
        $deal = $this->visible($request, $id);
        $deal = $this->deals->update($deal, $request->user(), $this->deals->validate($deal->tenant_id, $request->all(), creating: false));

        return response()->json(['data' => CrmPresenter::deal($deal)]);
    }

    /** Drag on the board: a stage, and where among its deals. */
    public function move(Request $request, string $id)
    {
        $deal = $this->visible($request, $id);

        $data = $request->validate([
            'stage_id' => ['required', 'integer'],
            'position' => ['nullable', 'integer', 'min:0'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $deal = $this->deals->move($deal, $request->user(), (int) $data['stage_id'], $data['position'] ?? null, $data['lost_reason'] ?? null);

        return response()->json(['data' => CrmPresenter::deal($deal)]);
    }

    public function destroy(Request $request, string $id)
    {
        $this->deals->delete($this->visible($request, $id), $request->user());

        return response()->json(['message' => __('crm.deals.deleted')]);
    }

    /** @param  array<string, mixed>  $data */
    private function filtered(Builder $query, array $data, $user): Builder
    {
        return $query
            ->when($data['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.$search.'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $like)->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like)));
            })
            ->when($data['owner'] ?? null, function (Builder $q, string $owner) use ($user) {
                match ($owner) {
                    'none' => $q->whereNull('owner_user_id'),
                    'me' => $q->where('owner_user_id', $user->id),
                    default => $q->where('owner_user_id', (int) $owner),
                };
            });
    }

    private function visible(Request $request, string $id): Deal
    {
        $deal = Deal::query()->visibleTo($request->user())->find($id);

        if (! $deal) {
            throw new HttpException(404, __('crm.deals.not_found'));
        }

        return $deal;
    }
}
