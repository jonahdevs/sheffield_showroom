<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the permissions list: what the capability is, which resource it
 * belongs to, and which roles hand it out.
 */
#[TypeScript(location: ['App', 'Data'])]
class PermissionRowData extends Data
{
    /**
     * @param  array<int, string>  $roles
     */
    public function __construct(
        public string $value,
        public string $label,
        public string $group,
        public string $group_label,
        public array $roles,
    ) {}

    /**
     * @param  Collection<int, Role>  $roles
     * @return array<int, self>
     */
    public static function forRoles(Collection $roles): array
    {
        $holders = $roles->mapWithKeys(fn (Role $role) => [
            $role->name => $role->permissions->pluck('name')->all(),
        ]);

        return array_map(fn (Permission $permission) => new self(
            value: $permission->value,
            label: $permission->label(),
            group: $permission->group(),
            group_label: Permission::groupLabel($permission->group()),
            roles: $holders
                ->filter(fn (array $held) => in_array($permission->value, $held, true))
                ->keys()
                ->all(),
        ), Permission::cases());
    }
}
