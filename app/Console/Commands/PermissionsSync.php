<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Permission as PermissionEnum;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Laravel\Prompts\table;

/**
 * Reconciles the `Permission` enum with the spatie tables, then runs
 * `RolesSeeder` so the roles it defines match what they should grant.
 *
 * Safe to run repeatedly: it creates what is missing and leaves a role
 * somebody has edited on the Roles screen alone.
 */
class PermissionsSync extends Command
{
    protected $signature = 'permissions:sync
                            {--prune : Delete permissions that no longer exist in the enum}';

    protected $description = 'Sync permissions from the Permission enum and seed the roles that grant them';

    public function handle(PermissionRegistrar $registrar): int
    {
        $before = Permission::query()->pluck('name')->all();

        $orphaned = array_values(array_diff($before, PermissionEnum::values()));
        $pruned = $this->option('prune') ? $this->prune($orphaned) : [];

        $registrar->forgetCachedPermissions();

        /* The seeder creates whatever the enum has gained and then sets each
           role from its definition, so the counting is done off what was in
           the table before it ran. */
        $this->callSilent('db:seed', [
            '--class' => RolesSeeder::class,
            '--force' => true,
        ]);

        $registrar->forgetCachedPermissions();

        $created = array_values(array_diff(
            Permission::query()->pluck('name')->all(),
            $before,
        ));

        $this->report($created, $orphaned, $pruned);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $orphaned
     * @return array<int, string>
     */
    private function prune(array $orphaned): array
    {
        Permission::query()->whereIn('name', $orphaned)->delete();

        return $orphaned;
    }

    /**
     * @param  array<int, string>  $created
     * @param  array<int, string>  $orphaned
     * @param  array<int, string>  $pruned
     */
    private function report(array $created, array $orphaned, array $pruned): void
    {
        $roles = RolesSeeder::definitions();

        table(
            ['Outcome', 'Count', 'Detail'],
            [
                ['In enum', (string) count(PermissionEnum::cases()), ''],
                ['Created', (string) count($created), implode(', ', array_slice($created, 0, 5))],
                ['Roles synced', (string) count($roles), implode(', ', array_keys($roles))],
                ['Pruned', (string) count($pruned), implode(', ', $pruned)],
            ]
        );

        if ($orphaned !== [] && $pruned === []) {
            $this->warn(sprintf(
                '%d permission(s) exist in the database but not in the enum: %s',
                count($orphaned),
                implode(', ', $orphaned)
            ));
            $this->line('Run with --prune to remove them.');
        }
    }
}
