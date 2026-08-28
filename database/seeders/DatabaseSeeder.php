<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Roles before users: UsersSeeder hands out the super admin role and
     * cannot do that until the role exists.
     */
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            UsersSeeder::class,
        ]);

        /* An account holding nothing, for seeing what the application looks
           like to somebody who has not been given a role yet. Guarded rather
           than firstOrCreate so the factory still supplies the password and
           the rest of its defaults on a first run. */
        if (! User::query()->where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
