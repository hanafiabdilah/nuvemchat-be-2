<?php

namespace Database\Seeders;

use App\Models\Admin;
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
            ['guard_name' => 'web', 'is_platform' => true]
        );

        $name = env('ADMIN_NAME', 'Back Office Admin');
        $email = env('ADMIN_EMAIL', 'admin@mail.com');
        $password = env('ADMIN_PASSWORD', '12345678');

        $admin = Admin::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password, // hashed via the Admin model cast
                'email_verified_at' => now(),
            ]
        );

        if (! $admin->hasRole('super-admin')) {
            $admin->assignRole('super-admin');
        }

        $this->command->info("Back Office admin ready: {$email}");
    }
}
