<?php

namespace App\Http\Controllers\Api\Crm;

use App\Enums\Crm\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\CrmPresenter;
use App\Models\CrmActivity;
use App\Services\Crm\ActivityService;
use App\Services\Crm\CrmSubjects;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * CRM › Activities: the log of what people did with customers — notes, calls,
 * meetings, e-mails — across the workspace, and writing one.
 */
class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivityService $activities,
    ) {}

    /**
     * The log: what people logged (notes, calls, meetings, e-mails) — or, with
     * `include_system`, everything the CRM recorded too.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', 'array'],
            'type.*' => ['string', 'max:40'],
            'user_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'required_with:subject_id', Rule::in(CrmSubjects::TYPES)],
            'subject_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'include_system' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $timezone = $user->tenant?->displayTimezone() ?? config('app.timezone');

        $page = CrmActivity::query()
            ->visibleTo($user)
            ->with(['user:id,name', 'subject'])
            ->when(! ($data['include_system'] ?? false), fn (Builder $q) => $q->whereIn('type', ActivityType::values()))
            ->when($data['type'] ?? null, fn (Builder $q, array $types) => $q->whereIn('type', $types))
            ->when($data['user_id'] ?? null, fn (Builder $q, int $userId) => $q->where('user_id', $userId))
            ->when($data['subject_type'] ?? null, fn (Builder $q, string $type) => $q->where('subject_type', $type)->where('subject_id', $data['subject_id'] ?? 0))
            ->when($data['customer_id'] ?? null, fn (Builder $q, int $customer) => $q->where('customer_id', $customer))
            ->when($data['from'] ?? null, fn (Builder $q, string $from) => $q->where('occurred_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->utc()))
            ->when($data['to'] ?? null, fn (Builder $q, string $to) => $q->where('occurred_at', '<=', Carbon::parse($to, $timezone)->endOfDay()->utc()))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 50);

        return response()->json([
            'data' => collect($page->items())->map(fn (CrmActivity $activity) => $this->shape($activity))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request)
    {
        $activity = $this->activities->create($request->user(), $this->activities->validate($request->all(), creating: true));

        return response()->json(['data' => $this->shape($activity->load('subject'))], 201);
    }

    public function update(Request $request, string $id)
    {
        $activity = $this->activities->update($this->visible($request, $id), $request->user(), $this->activities->validate($request->all(), creating: false));

        return response()->json(['data' => $this->shape($activity->load('subject'))]);
    }

    public function destroy(Request $request, string $id)
    {
        $this->activities->delete($this->visible($request, $id), $request->user());

        return response()->json(['message' => __('crm.activities.deleted')]);
    }

    /** @return array<string, mixed> */
    private function shape(CrmActivity $activity): array
    {
        $subject = $activity->relationLoaded('subject') ? $activity->subject : null;

        return CrmPresenter::activity($activity) + [
            'subject_name' => $subject ? ($subject->title ?? $subject->name ?? null) : null,
            'editable' => ActivityService::isManual($activity),
        ];
    }

    private function visible(Request $request, string $id): CrmActivity
    {
        $activity = CrmActivity::query()->visibleTo($request->user())->find($id);

        if (! $activity) {
            throw new HttpException(404, __('crm.activities.not_found'));
        }

        return $activity;
    }
}
