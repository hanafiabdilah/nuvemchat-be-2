<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\AdminListing;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    use AdminListing;

    /**
     * Paginated list of tenant users across every customer (platform-wide).
     *
     * Only users that belong to a tenant are returned; a row without one is an
     * account caught mid-registration, not a customer. Back Office admins are
     * a separate table entirely (see the Admins page).
     *
     * ?search= (name, e-mail, WhatsApp), ?tenant_id=, ?role=owner|member,
     * ?per_page=, ?sort=created_at|name|email (+ dir) — newest|oldest still work.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tenant_id' => ['sometimes', 'nullable', 'integer'],
            'role' => ['sometimes', 'nullable', Rule::in(['owner', 'member'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $digits = preg_replace('/\D+/', '', $search);
        [$sort, $dir] = $this->sortOf($request, ['created_at', 'name', 'email']);
        // Owners are the users a tenant points at; everyone else was invited.
        $owners = Tenant::query()->select('user_id');

        $users = User::query()
            ->whereNotNull('tenant_id')
            ->with(['roles', 'tenant.user'])
            ->when($validated['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when(($validated['role'] ?? null) === 'owner', fn ($q) => $q->whereIn('id', $owners))
            ->when(($validated['role'] ?? null) === 'member', fn ($q) => $q->whereNotIn('id', $owners))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->when(strlen($digits) >= 5, fn ($w2) => $w2->orWhere('whatsapp_number', 'like', "%{$digits}%"))))
            ->orderBy($sort === 'created_at' ? 'id' : $sort, $dir)
            ->orderBy('id', $dir)
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return AdminUserResource::collection($users);
    }

    /**
     * Edit a customer's user account: name, e-mail and, optionally, a new
     * password.
     *
     * This is the support path for "I mistyped my address at sign-up" and "I
     * am locked out". The operator is not the account's owner, so there is no
     * current password to ask for — the permission and the audit row are what
     * stand in its place.
     */
    public function update(Request $request, User $user)
    {
        // Same boundary as the list: a row without a tenant is not a customer
        // account, and Back Office admins live in another table altogether.
        if ($user->tenant_id === null) {
            abort(404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', Password::defaults()],
        ]);

        $previousEmail = $user->email;

        $user->name = $data['name'];
        $user->email = $data['email'];

        $changed = array_keys($user->getDirty());
        $changingEmail = $user->isDirty('email');
        $changingPassword = ! empty($data['password']);

        if ($changingPassword) {
            $user->password = $data['password']; // hashed via cast
            $changed[] = 'password';
        }

        $user->save();

        // ⚠️ Whoever held a session under the old address or the old password
        // must stop holding it — the same rule the customer's own profile page
        // follows. All of them go here, since none belongs to the operator.
        if ($changingEmail || $changingPassword) {
            $user->tokens()->delete();
        }

        if ($changed !== []) {
            AuditLog::record('users.update', "Updated user {$user->email} (tenant #{$user->tenant_id})", array_filter([
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'changed' => $changed,
                'previous_email' => $changingEmail ? $previousEmail : null,
            ]));
        }

        return new AdminUserResource($user->load(['roles', 'tenant.user']));
    }
}
