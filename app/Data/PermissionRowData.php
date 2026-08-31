<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the permissions list: what the capability is, which resource it
 * belongs to, and who hands it out.
 */
#[TypeScript(location: ['App', 'Data'])]
class PermissionRowData extends Data
{
    /**
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $users
     */
    public function __construct(
        public string $value,
        public string $label,
        public string $group,
        public string $group_label,
        public array $roles,
        /**
         * Accounts holding this capability without a role behind it.
         *
         * The reason the column exists at all: a direct grant is invisible on
         * the Roles screen, so an ability can outlive the role it was meant to
         * come with and nobody auditing roles would ever see it. Naming the
         * people here makes this page the one place the whole picture is true.
         */
        public array $users,
    ) {}

    /**
     * @param  Collection<int, Role>  $roles
     * @param  Collection<int, User>  $directHolders
     * @return array<int, self>
     */
    public static function forRoles(Collection $roles, Collection $directHolders): array
    {
        $held = $roles->mapWithKeys(fn (Role $role) => [
            $role->name => $role->permissions->pluck('name')->all(),
        ]);

        $pinned = $directHolders->mapWithKeys(fn (User $user) => [
            $user->name => $user->permissions->pluck('name')->all(),
        ]);

        return array_map(fn (Permission $permission) => new self(
            value: $permission->value,
            label: $permission->label(),
            group: $permission->group(),
            group_label: Permission::groupLabel($permission->group()),
            roles: $held
                ->filter(fn (array $names) => in_array($permission->value, $names, true))
                ->keys()
                ->all(),
            users: $pinned
                ->filter(fn (array $names) => in_array($permission->value, $names, true))
                ->keys()
                ->all(),
        ), Permission::cases());
    }
}
