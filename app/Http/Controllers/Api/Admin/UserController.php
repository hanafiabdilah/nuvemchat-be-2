<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * Paginated list of tenant users across every customer (platform-wide).
     *
     * Only users that belong to a tenant are returned; a row without one is an
     * account caught mid-registration, not a customer. Back Office admins are
     * a separate table entirely (see the Admins page). Supports ?search=,
     * ?tenant_id=, ?per_page= and ?sort= (newest|oldest).
     */
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));
        $sort = $request->query('sort', 'newest') === 'oldest' ? 'asc' : 'desc';

        $users = User::query()
            ->whereNotNull('tenant_id')
            ->with(['roles', 'tenant.user'])
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('id', $sort)
            ->paginate($perPage)
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
