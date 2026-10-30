<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmSavedView;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Saved views: a CRM list page's filters under a name. Private to the person
 * who saved it, or shared with the workspace — sharing (and changing a shared
 * view) is `crm-settings.manage`, since everyone sees the result.
 */
class SavedViewController extends Controller
{
    public const MAX_PER_ENTITY = 50;

    public function index(Request $request)
    {
        $data = $request->validate(['entity' => ['required', Rule::in(CrmSavedView::ENTITIES)]]);
        $user = $request->user();

        return response()->json([
            'data' => CrmSavedView::query()->visibleTo($user)->where('entity', $data['entity'])
                ->with('user:id,name')
                ->orderByDesc('shared')->orderBy('name')
                ->get()
                ->map(fn (CrmSavedView $view) => $this->shape($view, $request))
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'entity' => ['required', Rule::in(CrmSavedView::ENTITIES)],
            'name' => ['required', 'string', 'max:100'],
            'filters' => ['present', 'array', 'max:20'],
            'shared' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['shared']) && ! $user->can('crm-settings.manage')) {
            throw new HttpException(403, __('crm.views.share_forbidden'));
        }

        if (CrmSavedView::query()->visibleTo($user)->where('entity', $data['entity'])->count() >= self::MAX_PER_ENTITY) {
            throw new HttpException(422, __('crm.views.too_many', ['max' => self::MAX_PER_ENTITY]));
        }

        $view = CrmSavedView::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'entity' => $data['entity'],
            'name' => $data['name'],
            'filters' => self::clean($data['filters']),
            'shared' => (bool) ($data['shared'] ?? false),
        ]);

        return response()->json(['data' => $this->shape($view->load('user:id,name'), $request)], 201);
    }

    public function update(Request $request, string $id)
    {
        $view = $this->editable($request, $id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'filters' => ['sometimes', 'array', 'max:20'],
            'shared' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('shared', $data) && $data['shared'] !== $view->shared && ! $request->user()->can('crm-settings.manage')) {
            throw new HttpException(403, __('crm.views.share_forbidden'));
        }

        if (isset($data['filters'])) {
            $data['filters'] = self::clean($data['filters']);
        }

        $view->fill($data)->save();

        return response()->json(['data' => $this->shape($view->load('user:id,name'), $request)]);
    }

    public function destroy(Request $request, string $id)
    {
        $this->editable($request, $id)->delete();

        return response()->json(['message' => __('crm.views.deleted')]);
    }

    /**
     * Filters are the list page's own query parameters, kept as they were
     * given — only scalars and lists of scalars, nothing nested.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function clean(array $filters): array
    {
        return collect($filters)
            ->filter(fn ($value, $key) => is_string($key) && mb_strlen($key) <= 40)
            ->map(fn ($value) => is_array($value)
                ? array_values(array_filter($value, fn ($item) => is_scalar($item)))
                : (is_scalar($value) || $value === null ? $value : null))
            ->filter(fn ($value) => $value !== null && $value !== '' && $value !== [])
            ->all();
    }

    /** Your own views, or shared ones when you may manage CRM settings. */
    private function editable(Request $request, string $id): CrmSavedView
    {
        $user = $request->user();
        $view = CrmSavedView::query()->visibleTo($user)->find($id) ?? throw new HttpException(404, __('crm.views.not_found'));

        if ($view->user_id !== $user->id && ! $user->can('crm-settings.manage')) {
            throw new HttpException(403, __('crm.views.not_yours'));
        }

        return $view;
    }

    /** @return array<string, mixed> */
    private function shape(CrmSavedView $view, Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $view->id,
            'entity' => $view->entity,
            'name' => $view->name,
            'filters' => (object) ($view->filters ?? []),
            'shared' => $view->shared,
            'owner' => $view->user ? ['id' => $view->user->id, 'name' => $view->user->name] : null,
            'editable' => $view->user_id === $user->id || $user->can('crm-settings.manage'),
        ];
    }
}
