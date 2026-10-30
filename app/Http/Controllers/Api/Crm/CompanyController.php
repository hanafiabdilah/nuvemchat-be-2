<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\CompanyResource;
use App\Http\Resources\Crm\CustomerResource;
use App\Models\Company;
use App\Models\CrmActivity;
use App\Models\Customer;
use App\Models\User;
use App\Services\Crm\CrmTimeline;
use App\Services\Crm\CustomFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The organisations customers work for. A company belongs to the workspace;
 * its people list is narrowed to the customers the viewer may see.
 */
class CompanyController extends Controller
{
    private const TRACKED = ['name', 'domain', 'industry', 'email', 'phone', 'website', 'address', 'owner_user_id'];

    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'owner' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        $companies = Company::query()
            ->where('tenant_id', $user->tenant_id)
            ->with('owner:id,name,avatar_path')
            ->withCount(['customers' => fn (Builder $q) => $q->visibleTo($user)])
            ->when($data['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.$search.'%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('domain', 'like', $like)->orWhere('industry', 'like', $like));
            })
            ->when($data['owner'] ?? null, function (Builder $q, string $owner) use ($user) {
                match ($owner) {
                    'none' => $q->whereNull('owner_user_id'),
                    'me' => $q->where('owner_user_id', $user->id),
                    default => $q->where('owner_user_id', (int) $owner),
                };
            })
            ->orderBy('name')
            ->paginate($data['per_page'] ?? 50);

        return CompanyResource::collection($companies);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $company = $this->company($request, $id);
        $company->load('owner:id,name,avatar_path');
        $company->loadCount(['customers' => fn (Builder $q) => $q->visibleTo($user)]);

        $customers = Customer::query()
            ->visibleTo($user)
            ->where('company_id', $company->id)
            ->with(['contacts:id,customer_id,channel,photo_profile', 'contacts.tags', 'owner:id,name,avatar_path'])
            ->withMax('conversations as last_interaction_at', 'last_message_at')
            ->orderBy('name')
            ->limit(200)
            ->get();

        $activities = CrmActivity::query()
            ->where('subject_type', $company->getMorphClass())
            ->where('subject_id', $company->id)
            ->with('user:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (CrmActivity $activity) => [
                'id' => 'a-'.$activity->id,
                'type' => $activity->type,
                'occurred_at' => $activity->occurred_at->toIso8601String(),
                'user' => $activity->user ? ['id' => $activity->user->id, 'name' => $activity->user->name] : null,
                'data' => $activity->data ?? (object) [],
            ]);

        return response()->json([
            'data' => (new CompanyResource($company))->resolve(),
            'customers' => CustomerResource::collection($customers)->resolve(),
            'timeline' => $activities->values(),
            'custom_field_definitions' => CustomFields::definitions($company->tenant_id, 'company')->map->only(['key', 'label', 'type', 'options'])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validated($request, $user->tenant_id, creating: true);

        $company = DB::transaction(function () use ($user, $data) {
            $company = Company::create([
                'tenant_id' => $user->tenant_id,
                ...collect($data)->except('custom_fields')->all(),
                'custom_fields' => CustomFields::apply($user->tenant_id, 'company', [], $data['custom_fields'] ?? []) ?: null,
            ]);

            CrmTimeline::record($company, CrmTimeline::CREATED, $user);

            return $company;
        });

        return (new CompanyResource($company->load('owner')))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $company = $this->company($request, $id);
        $data = $this->validated($request, $company->tenant_id, creating: false);

        $before = $company->only(self::TRACKED);

        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = CustomFields::apply($company->tenant_id, 'company', $company->custom_fields ?? [], $data['custom_fields'] ?? []) ?: null;
        }

        $company->fill($data)->save();

        $changes = CrmTimeline::diff($before, $company->only(self::TRACKED));

        if (isset($changes['owner_user_id'])) {
            $changes['owner_user_id']['from_name'] = User::find($changes['owner_user_id']['from'])?->name;
            $changes['owner_user_id']['to_name'] = $company->owner?->name;
        }

        if ($changes !== []) {
            CrmTimeline::record($company, CrmTimeline::UPDATED, $user, ['changes' => $changes]);
        }

        return new CompanyResource($company->fresh('owner'));
    }

    /** Its customers stay, without a company. */
    public function destroy(Request $request, string $id)
    {
        $company = $this->company($request, $id);

        DB::transaction(function () use ($company) {
            Customer::query()->where('company_id', $company->id)->update(['company_id' => null]);
            CrmActivity::query()->where('subject_type', $company->getMorphClass())->where('subject_id', $company->id)->delete();
            $company->delete();
        });

        return response()->json(['message' => __('crm.companies.deleted')]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $tenantId, bool $creating): array
    {
        $sometimes = $creating ? [] : ['sometimes'];

        return $request->validate([
            'name' => [...$sometimes, 'required', 'string', 'max:255'],
            'domain' => [...$sometimes, 'nullable', 'string', 'max:255'],
            'industry' => [...$sometimes, 'nullable', 'string', 'max:255'],
            'email' => [...$sometimes, 'nullable', 'email', 'max:255'],
            'phone' => [...$sometimes, 'nullable', 'string', 'max:32'],
            'website' => [...$sometimes, 'nullable', 'url', 'max:255'],
            'address' => [...$sometimes, 'nullable', 'string', 'max:1000'],
            'owner_user_id' => [...$sometimes, 'nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'custom_fields' => [...$sometimes, 'nullable', 'array'],
        ]);
    }

    private function company(Request $request, string $id): Company
    {
        $company = Company::query()->where('tenant_id', $request->user()->tenant_id)->find($id);

        if (! $company) {
            throw new HttpException(404, __('crm.companies.not_found'));
        }

        return $company;
    }
}
