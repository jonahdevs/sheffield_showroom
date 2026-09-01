<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UsersSeeder extends Seeder
{
    /** Depends on `RolesSeeder` having run first. */
    public function run(): void
    {
        $admin = User::query()->firstOrCreate(
            ['email' => 'jonah.wakahiu@sheffieldafrica.com'],
            [
                'name' => 'Jonah Wakahiu',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $admin->syncRoles([Role::SUPER_ADMIN]);
    }
}
