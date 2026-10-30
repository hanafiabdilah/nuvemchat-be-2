<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmSegment;
use App\Models\Customer;
use App\Services\Crm\CustomerFilter;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * CRM › Segments: named groups of customers defined by rules, shared across
 * the workspace and evaluated live.
 */
class SegmentController extends Controller
{
    public const MAX_SEGMENTS = 100;

    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'data' => CrmSegment::query()
                ->where('tenant_id', $user->tenant_id)
                ->with('creator:id,name')
                ->orderBy('name')
                ->limit(self::MAX_SEGMENTS)
                ->get()
                ->map(fn (CrmSegment $segment) => $this->shape($segment, $request))
                ->values(),
            'fields' => CustomerFilter::FIELDS,
        ]);
    }

    public function show(Request $request, string $id)
    {
        return response()->json(['data' => $this->shape($this->find($request, $id), $request)]);
    }

    /** How many customers rules would select, and the first few — before saving. */
    public function preview(Request $request)
    {
        $user = $request->user();
        $rules = CustomerFilter::validate($user->tenant_id, $request->all());
        $query = CustomerFilter::applyRules(Customer::query()->visibleTo($user), $rules['match'], $rules['rules']);

        return response()->json([
            'count' => (clone $query)->count(),
            'sample' => $query->orderByDesc('customers.id')->limit(8)->get(['customers.id', 'customers.name', 'customers.status'])
                ->map(fn (Customer $customer) => ['id' => $customer->id, 'name' => $customer->name, 'status' => $customer->status?->value])
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (CrmSegment::query()->where('tenant_id', $user->tenant_id)->count() >= self::MAX_SEGMENTS) {
            throw new HttpException(422, __('crm.segments.too_many', ['max' => self::MAX_SEGMENTS]));
        }

        $meta = $this->validateMeta($request);
        $rules = CustomerFilter::validate($user->tenant_id, $request->all());

        $segment = CrmSegment::create([
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            ...$meta,
            ...$rules,
        ]);

        return response()->json(['data' => $this->shape($segment->load('creator:id,name'), $request)], 201);
    }

    public function update(Request $request, string $id)
    {
        $segment = $this->find($request, $id);
        $meta = $this->validateMeta($request);
        $rules = $request->has('rules') ? CustomerFilter::validate($segment->tenant_id, $request->all()) : [];

        $segment->fill([...$meta, ...$rules])->save();

        return response()->json(['data' => $this->shape($segment->load('creator:id,name'), $request)]);
    }

    public function destroy(Request $request, string $id)
    {
        $this->find($request, $id)->delete();

        return response()->json(['message' => __('crm.segments.deleted')]);
    }

    /** @return array<string, mixed> */
    private function validateMeta(Request $request): array
    {
        $creating = $request->isMethod('post');

        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(CrmSegment $segment, Request $request): array
    {
        $query = CustomerFilter::applyRules(Customer::query()->visibleTo($request->user()), $segment->match, $segment->rules ?? []);

        return [
            'id' => $segment->id,
            'name' => $segment->name,
            'description' => $segment->description,
            'match' => $segment->match,
            'rules' => $segment->rules ?? [],
            'customers_count' => $query->count(),
            'creator' => $segment->creator ? ['id' => $segment->creator->id, 'name' => $segment->creator->name] : null,
            'created_at' => $segment->created_at?->toIso8601String(),
            'updated_at' => $segment->updated_at?->toIso8601String(),
        ];
    }

    private function find(Request $request, string $id): CrmSegment
    {
        return CrmSegment::query()->where('tenant_id', $request->user()->tenant_id)->find($id)
            ?? throw new HttpException(404, __('crm.segments.not_found'));
    }
}
