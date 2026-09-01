<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

# =========================================================================
# What every role in this showroom is allowed to do
# =========================================================================
#
# `permissions:sync` reads `definitions()` rather than keeping a second copy,
# so there is nowhere for the two to drift apart.

class RolesSeeder extends Seeder
{
    /**
     * `is_system` marks a role the application depends on by name, and those
     * are forced back into line on every run. The rest are only a starting
     * point, so re-running leaves what the Roles screen made of them.
     *
     * @return array<string, array{description: string, is_system: bool, permissions: array<int, PermissionEnum>}>
     */
    public static function definitions(): array
    {
        return [
            Role::SUPER_ADMIN => [
                'description' => 'Unrestricted. Bypasses every check via Gate::before.',
                'is_system' => true,
                'permissions' => PermissionEnum::cases(),
            ],

            'manager' => [
                'description' => 'Runs the showroom floor: every visit, customer and product, plus the reports off them.',
                'is_system' => false,
                'permissions' => [
                    PermissionEnum::DashboardView,

                    PermissionEnum::VisitsViewAny,
                    PermissionEnum::VisitsViewOwn,
                    PermissionEnum::VisitsCreate,
                    PermissionEnum::VisitsUpdate,
                    PermissionEnum::VisitsDelete,
                    PermissionEnum::VisitsExport,

                    PermissionEnum::CustomersViewAny,
                    PermissionEnum::CustomersCreate,
                    PermissionEnum::CustomersUpdate,
                    PermissionEnum::CustomersDelete,
                    PermissionEnum::CustomersExport,

                    PermissionEnum::ProductsViewAny,
                    PermissionEnum::ProductsCreate,
                    PermissionEnum::ProductsUpdate,
                    PermissionEnum::ProductsDelete,

                    # Staffs their own team without being trusted to widen
                    # what that team may touch: the roles are shaped above
                    # them.
                    PermissionEnum::UsersViewAny,
                    PermissionEnum::UsersCreate,
                    PermissionEnum::UsersUpdate,
                    PermissionEnum::RolesView,
                    PermissionEnum::RolesAssign,
                ],
            ],

            Role::RECEPTION => [
                'description' => 'Signs visitors in at the front desk and reads the day off the log.',
                'is_system' => false,
                'permissions' => [
                    PermissionEnum::VisitsViewAny,
                    PermissionEnum::VisitsCreate,

                    PermissionEnum::VisitsExport,

                    PermissionEnum::CustomersViewAny,
                    PermissionEnum::CustomersCreate,
                ],
            ],

            'sales' => [
                'description' => 'Logs the visits they take and reads the catalogue behind them.',
                'is_system' => false,
                'permissions' => [
                    PermissionEnum::DashboardView,

                    PermissionEnum::VisitsViewOwn,
                    PermissionEnum::VisitsCreate,
                    PermissionEnum::VisitsUpdate,

                    PermissionEnum::CustomersViewAny,
                    PermissionEnum::CustomersCreate,
                    PermissionEnum::CustomersUpdate,

                    PermissionEnum::ProductsViewAny,
                ],
            ],
        ];
    }

    public function run(): void
    {
        $guard = config('auth.defaults.guard');

        $this->createMissingPermissions($guard);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () use ($guard) {
            foreach (self::definitions() as $name => $definition) {
                $role = Role::query()->firstOrNew([
                    'name' => $name,
                    'guard_name' => $guard,
                ]);

                $isNew = ! $role->exists;

                $role->is_system = $definition['is_system'];
                $role->description = $definition['description'];
                $role->save();

                # A role somebody has since edited on the Roles screen keeps
                # what they gave it. Only a brand new one, or a system role
                # whose grants are written in code, is set from here.
                if ($isNew || $definition['is_system']) {
                    $role->syncPermissions(array_map(
                        fn (PermissionEnum $permission) => $permission->value,
                        $definition['permissions'],
                    ));
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<int, string>
     */
    protected function createMissingPermissions(string $guard): array
    {
        $existing = Permission::query()
            ->where('guard_name', $guard)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff(PermissionEnum::values(), $existing));

        foreach ($missing as $name) {
            Permission::create(['name' => $name, 'guard_name' => $guard]);
        }

        return $missing;
    }
}
