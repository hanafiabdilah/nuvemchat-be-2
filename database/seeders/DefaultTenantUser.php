<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DefaultTenantUser extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::doesntHave('tenant')->where('role', 'owner')->get();

        foreach($users as $user){
            $tenant = $user->tenant()->create([]);
            $user->tenant_id = $tenant->id;
            $user->save();
        }
    }
}
