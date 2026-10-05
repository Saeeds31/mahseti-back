<?php

namespace Modules\Users\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Users\Models\User;
use Modules\Users\Models\Role;

class UsersTableSeeder extends Seeder
{
    public function run()
    {
        $superAdmin = User::firstOrCreate(
            ['mobile' => '09113894304'],
            [
                'full_name' => 'Super Admin',
                'password' => Hash::make('superAdmin#123'),
            ]
        );

        $role = Role::where('slug', 'super_admin')->first();
        if ($role && !$superAdmin->roles()->where('role_id', $role->id)->exists()) {
            $superAdmin->roles()->attach($role->id);
        }
    }
}
