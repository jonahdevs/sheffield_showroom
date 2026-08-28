<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Permission;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class PermissionGroupData extends Data
{
    /**
     * @param  array<int, PermissionOptionData>  $permissions
     */
    public function __construct(
        public string $group,
        public string $group_label,
        public array $permissions,
    ) {}

    /**
     * @param  array<int, string>  $grantable
     * @return array<int, self>
     */
    public static function matrix(array $grantable): array
    {
        $groups = [];

        foreach (Permission::grouped() as $group => $permissions) {
            $groups[] = new self(
                group: $group,
                group_label: Permission::groupLabel($group),
                permissions: array_map(
                    fn (Permission $permission) => new PermissionOptionData(
                        value: $permission->value,
                        label: $permission->label(),
                        grantable: in_array($permission->value, $grantable, true),
                    ),
                    $permissions,
                ),
            );
        }

        return $groups;
    }
}
