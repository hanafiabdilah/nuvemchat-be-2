<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Migrate existing role data from users table to model_has_roles
        $users = DB::table('users')->whereNotNull('role')->get();

        foreach ($users as $user) {
            $role = Role::where('name', $user->role)->first();

            if ($role) {
                // Insert into model_has_roles if not exists
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $role->id,
                    'model_type' => 'App\Models\User',
                    'model_id' => $user->id,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Migrate data back from model_has_roles to users.role column
        $userRoles = DB::table('model_has_roles')
            ->where('model_type', 'App\Models\User')
            ->get();

        foreach ($userRoles as $userRole) {
            $role = DB::table('roles')->where('id', $userRole->role_id)->first();

            if ($role && Schema::hasColumn('users', 'role')) {
                DB::table('users')
                    ->where('id', $userRole->model_id)
                    ->update(['role' => $role->name]);
            }
        }

        // Remove all user role assignments from spatie tables
        DB::table('model_has_roles')
            ->where('model_type', 'App\Models\User')
            ->delete();
    }
};
