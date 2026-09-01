<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The order is load-bearing: roles before users, and customers before
     * visits. `CustomersSeeder` replaces whatever is in the table, so nothing
     * may write customers after it.
     */
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            UsersSeeder::class,
            CustomersSeeder::class,
            VisitsSeeder::class,
            RewardCampaignSeeder::class,
        ]);

        # An account holding nothing, for seeing the application as somebody
        # with no role yet. Guarded rather than `firstOrCreate` so the factory
        # still supplies the password and its other defaults on a first run.
        if (! User::query()->where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
