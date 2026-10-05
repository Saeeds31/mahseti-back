<?php

namespace Modules\Users\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Users\Models\Role;

class RolesTableSeeder extends Seeder
{
    public function run()
    {
        Role::firstOrCreate(
            ['name' => 'super_admin'],
            ['description' => 'Full access to the system']
        );

        Role::firstOrCreate(
            ['name' => 'customer'],
            ['description' => 'Regular customer with limited access']
        );
    }
}
