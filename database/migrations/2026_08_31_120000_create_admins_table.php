<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Back Office admins move out of `users` into their own table.
 *
 * They were only ever tenant users with `tenant_id = null` and a platform
 * role, which made every admin account occupy an address in the customers'
 * namespace: a business could not sign up with the email its own operator uses
 * to log into the Back Office, and the failure said "email already taken" —
 * naming a row that account holder cannot see and support cannot explain.
 * The two are different populations with different lifecycles, so they get
 * different tables and the unique index stops being shared.
 *
 * Ids are carried across deliberately. `audit_logs.actor_id` and
 * `subscriptions.manual_granted_by` already hold admin ids, and reusing them
 * keeps years of "who did this" pointing at the right person instead of at
 * whichever customer happens to hold that id afterwards.
 */
return new class extends Migration
{
    private const ADMIN_MODEL = 'App\Models\Admin';

    private const USER_MODEL = 'App\Models\User';

    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Unique among admins only — the whole point of the move.
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // The audit trail can be written by an admin (nearly all of it) or by a
        // tenant user (revealing an API Way token is audited too), so the actor
        // needs a type now that the id alone no longer says which table.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['actor_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_type')->nullable()->after('actor_id');
        });

        // Points at `admins` from here on, so the constraint has to go.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['manual_granted_by']);
        });

        $this->migrateExistingAdmins();
    }

    /**
     * Copy every platform user into `admins`, move what points at them, and
     * free the email.
     */
    private function migrateExistingAdmins(): void
    {
        $platformRoleIds = DB::table('roles')->where('is_platform', true)->pluck('id');

        $adminIds = $platformRoleIds->isEmpty()
            ? collect()
            : DB::table('users')
                ->whereNull('tenant_id')
                ->whereIn('id', fn ($q) => $q
                    ->select('model_id')
                    ->from('model_has_roles')
                    ->where('model_type', self::USER_MODEL)
                    ->whereIn('role_id', $platformRoleIds))
                ->pluck('id');

        // Every audit row written by someone who is not becoming an admin was
        // written by a tenant user. Stamp both sides before the rows move, so
        // no row is left with an id whose table is a guess.
        DB::table('audit_logs')->whereNotNull('actor_id')
            ->update(['actor_type' => self::USER_MODEL]);

        if ($adminIds->isEmpty()) {
            return;
        }

        foreach ($adminIds->chunk(500) as $chunk) {
            $ids = $chunk->all();

            $rows = DB::table('users')->whereIn('id', $ids)
                ->get(['id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at'])
                ->map(fn ($row) => (array) $row)
                ->all();

            DB::table('admins')->insert($rows);

            // Roles and live sessions follow the account: an admin signed in
            // when this deploys stays signed in, and keeps their permissions.
            DB::table('model_has_roles')
                ->where('model_type', self::USER_MODEL)->whereIn('model_id', $ids)
                ->update(['model_type' => self::ADMIN_MODEL]);

            DB::table('model_has_permissions')
                ->where('model_type', self::USER_MODEL)->whereIn('model_id', $ids)
                ->update(['model_type' => self::ADMIN_MODEL]);

            DB::table('personal_access_tokens')
                ->where('tokenable_type', self::USER_MODEL)->whereIn('tokenable_id', $ids)
                ->update(['tokenable_type' => self::ADMIN_MODEL]);

            DB::table('audit_logs')->whereIn('actor_id', $ids)
                ->update(['actor_type' => self::ADMIN_MODEL]);

            // Last: the row whose email we came here to release.
            DB::table('users')->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
        // Put the accounts back where they came from, ids and all.
        $rows = DB::table('admins')->get()->map(fn ($row) => array_merge((array) $row, ['tenant_id' => null]))->all();

        if ($rows !== []) {
            DB::table('users')->insert($rows);

            $ids = array_column($rows, 'id');

            DB::table('model_has_roles')
                ->where('model_type', self::ADMIN_MODEL)->whereIn('model_id', $ids)
                ->update(['model_type' => self::USER_MODEL]);

            DB::table('model_has_permissions')
                ->where('model_type', self::ADMIN_MODEL)->whereIn('model_id', $ids)
                ->update(['model_type' => self::USER_MODEL]);

            DB::table('personal_access_tokens')
                ->where('tokenable_type', self::ADMIN_MODEL)->whereIn('tokenable_id', $ids)
                ->update(['tokenable_type' => self::USER_MODEL]);
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreign('manual_granted_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('actor_type');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::dropIfExists('admins');
    }
};
