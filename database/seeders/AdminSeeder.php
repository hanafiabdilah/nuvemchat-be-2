<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    /**
     * Create the first-time Back Office (platform) admin.
     *
     * Credentials are read from .env so they can be overridden per
     * environment; otherwise sensible local defaults are used.
     *
     *   ADMIN_NAME, ADMIN_EMAIL, ADMIN_PASSWORD
     */
    public function run(): void
    {
        // Make sure the role exists even if this seeder is run standalone.
        Role::firstOrCreate(
            ['name' => 'super-admin'],
            ['guard_name' => 'web']
        );

        $name = env('ADMIN_NAME', 'Back Office Admin');
        $email = env('ADMIN_EMAIL', 'admin@mail.com');
        $password = env('ADMIN_PASSWORD', '12345678');

        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password, // hashed via the User model cast
                'email_verified_at' => now(),
            ]
        );

        // A platform admin is never scoped to a tenant.
        if (! is_null($admin->tenant_id)) {
            $admin->tenant_id = null;
            $admin->save();
        }

        if (! $admin->hasRole('super-admin')) {
            $admin->assignRole('super-admin');
        }

        $this->command->info("Back Office admin ready: {$email}");
    }
}
