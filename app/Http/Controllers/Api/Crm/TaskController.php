<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\CrmPresenter;
use App\Models\CrmTask;
use App\Models\User;
use App\Services\Crm\CrmSubjects;
use App\Services\Crm\TaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** CRM › Tasks: follow-ups with a due time and a reminder. */
class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    /**
     * The list. `view`: open (default) | overdue | today | upcoming | done.
     * `assignee`: me (default) | created | all | none | a user id. Days are
     * the workspace's calendar days.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'view' => ['nullable', Rule::in(['open', 'overdue', 'today', 'upcoming', 'done'])],
            'assignee' => ['nullable', 'string', 'max:20'],
            'subject_type' => ['nullable', 'required_with:subject_id', Rule::in(CrmSubjects::TYPES)],
            'subject_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $timezone = $user->tenant?->displayTimezone() ?? config('app.timezone');
        $startOfDay = Carbon::now($timezone)->startOfDay()->utc();
        $endOfDay = Carbon::now($timezone)->endOfDay()->utc();
        $view = $data['view'] ?? 'open';

        $query = CrmTask::query()
            ->visibleTo($user)
            ->with(['assignee:id,name,avatar_path', 'creator:id,name', 'customer:id,name', 'subject'])
            ->when($data['search'] ?? null, fn (Builder $q, string $search) => $q->where('title', 'like', '%'.$search.'%'))
            ->when($data['subject_type'] ?? null, fn (Builder $q, string $type) => $q->where('subject_type', $type)->where('subject_id', $data['subject_id'] ?? 0))
            ->when($data['customer_id'] ?? null, fn (Builder $q, int $customer) => $q->where('customer_id', $customer));

        $this->forAssignee($query, $data['assignee'] ?? 'me', $user);

        match ($view) {
            'done' => $query->whereNotNull('completed_at')->orderByDesc('completed_at'),
            'overdue' => $query->whereNull('completed_at')->where('due_at', '<', now())->orderBy('due_at'),
            'today' => $query->whereNull('completed_at')->whereBetween('due_at', [$startOfDay, $endOfDay])->orderBy('due_at'),
            'upcoming' => $query->whereNull('completed_at')->where('due_at', '>', $endOfDay)->orderBy('due_at'),
            // Open: dated first by due time, then undated by when written.
            default => $query->whereNull('completed_at')->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('id'),
        };

        $page = $query->paginate($data['per_page'] ?? 50);

        // The counts behind the view tabs, for the same assignee filter.
        $counts = CrmTask::query()->visibleTo($user)->whereNull('completed_at');
        $this->forAssignee($counts, $data['assignee'] ?? 'me', $user);

        return response()->json([
            'data' => collect($page->items())->map(fn (CrmTask $task) => CrmPresenter::task($task))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => [
                'open' => (clone $counts)->count(),
                'overdue' => (clone $counts)->where('due_at', '<', now())->count(),
                'today' => (clone $counts)->whereBetween('due_at', [$startOfDay, $endOfDay])->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $task = $this->tasks->create($user, $this->tasks->validate($user->tenant_id, $request->all(), creating: true));

        return response()->json(['data' => CrmPresenter::task($task->load('subject'))], 201);
    }

    public function update(Request $request, string $id)
    {
        $task = $this->visible($request, $id);
        $data = $this->tasks->validate($task->tenant_id, array_merge(
            // remind_at is checked against due_at: when only one is sent, the
            // other is the one already set.
            ['due_at' => $task->due_at?->toIso8601String(), 'remind_at' => $task->remind_at?->toIso8601String()],
            $request->except(['subject_type', 'subject_id', 'conversation_id']),
        ), creating: false);

        $task = $this->tasks->update($task, $request->user(), $data);

        return response()->json(['data' => CrmPresenter::task($task->load('subject'))]);
    }

    public function complete(Request $request, string $id)
    {
        $task = $this->tasks->complete($this->visible($request, $id), $request->user());

        return response()->json(['data' => CrmPresenter::task($task->load('subject'))]);
    }

    public function reopen(Request $request, string $id)
    {
        $task = $this->tasks->reopen($this->visible($request, $id));

        return response()->json(['data' => CrmPresenter::task($task->load('subject'))]);
    }

    public function destroy(Request $request, string $id)
    {
        $this->tasks->delete($this->visible($request, $id));

        return response()->json(['message' => __('crm.tasks.deleted')]);
    }

    private function forAssignee(Builder $query, string $assignee, User $user): void
    {
        match ($assignee) {
            'all' => null,
            'none' => $query->whereNull('assignee_user_id'),
            'created' => $query->where('created_by', $user->id),
            'me' => $query->where('assignee_user_id', $user->id),
            default => $query->where('assignee_user_id', (int) $assignee),
        };
    }

    private function visible(Request $request, string $id): CrmTask
    {
        $task = CrmTask::query()->visibleTo($request->user())->find($id);

        if (! $task) {
            throw new HttpException(404, __('crm.tasks.not_found'));
        }

        return $task;
    }
}
