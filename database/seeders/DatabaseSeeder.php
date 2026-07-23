<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles and permissions first
        $this->call(RoleAndPermissionSeeder::class);

        // User::factory(10)->create();

        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'email_verified_at' => now(),
            ]
        );

        // Assign owner role to test user
        if (!$user->hasRole('owner')) {
            $user->assignRole('owner');
        }

        // Platform RBAC (Back Office roles & permissions) then the first admin
        $this->call(PlatformRbacSeeder::class);
        $this->call(AdminSeeder::class);

        // Billing plans + comp backfill for existing tenants
        $this->call(PlanSeeder::class);
        $this->call(WhatsappApiPlanSeeder::class);

        // Platform settings (API Way credentials, etc.)
        $this->call(SettingSeeder::class);
    }
}
