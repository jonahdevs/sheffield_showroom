<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Str;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Every capability the application checks for.
 *
 * Permissions are declared here rather than in the database: a check in a
 * controller or a policy compares against one of these values, so a row with
 * no case behind it is a promise nothing keeps. `permissions:sync` reconciles
 * this enum into the spatie tables.
 *
 * The value reads `group.action`, and the group before the dot is what the
 * permission matrix and the permissions list group by.
 */
#[TypeScript]
enum Permission: string
{
    // =========================================================================
    // Dashboard
    // =========================================================================

    case DashboardView = 'dashboard.view';

    // =========================================================================
    // Visits
    // =========================================================================

    /* The split every operational resource here needs: a manager sees the
       whole showroom's visits, a salesperson sees the ones they logged. */
    case VisitsViewAny = 'visits.view.any';
    case VisitsViewOwn = 'visits.view.own';
    case VisitsCreate = 'visits.create';
    case VisitsUpdate = 'visits.update';
    case VisitsDelete = 'visits.delete';
    case VisitsExport = 'visits.export';

    // =========================================================================
    // Customers
    // =========================================================================

    case CustomersViewAny = 'customers.view.any';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersDelete = 'customers.delete';
    case CustomersExport = 'customers.export';

    /* Reading the list out and writing a file of it back are not the same
       trust: an export leaks, an import rewrites hundreds of records at once
       and there is no undo behind it. */
    case CustomersImport = 'customers.import';

    // =========================================================================
    // Products
    // =========================================================================

    case ProductsViewAny = 'products.view.any';
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case ProductsDelete = 'products.delete';

    // =========================================================================
    // Administration
    // =========================================================================

    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';

    /* Handing a role to somebody, which is not the same as editing what the
       role can do. A supervisor may staff their own team without being
       trusted to widen what that team is allowed to touch. */
    case RolesAssign = 'roles.assign';

    case UsersViewAny = 'users.view.any';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';

    /**
     * The domain this permission belongs to, used to group the permission matrix.
     */
    public function group(): string
    {
        return Str::before($this->value, '.');
    }

    public function label(): string
    {
        return Str::of($this->value)
            ->after('.')
            ->replace(['.', '_'], ' ')
            ->ucfirst()
            ->toString();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Every permission keyed by group, in declaration order.
     *
     * @return array<string, array<int, self>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::cases() as $permission) {
            $grouped[$permission->group()][] = $permission;
        }

        return $grouped;
    }

    public static function groupLabel(string $group): string
    {
        return Str::of($group)->replace('_', ' ')->headline()->toString();
    }
}
