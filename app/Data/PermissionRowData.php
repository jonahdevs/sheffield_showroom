<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

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
        # Direct holders. A pinned grant is invisible on the Roles screen and
        # outlives the role it came with, so this page is the only full audit.
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
