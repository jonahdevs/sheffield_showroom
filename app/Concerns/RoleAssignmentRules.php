<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One ceiling, shared by every screen that hands out reach: you cannot grant a
 * permission you do not hold, nor a role holding one - otherwise assigning becomes the
 * way around the grant check. A second copy of this rule would drift into a hole.
 */
trait RoleAssignmentRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function roleRules(bool $optional = false): array
    {
        return [
            'roles' => [$optional ? 'sometimes' : 'present', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function permissionRules(bool $optional = false): array
    {
        return [
            'permissions' => [$optional ? 'sometimes' : 'present', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ];
    }

    protected function refuseRolesBeyondReach(Validator $validator): void
    {
        $beyondReach = Role::query()
            ->whereIn('name', (array) $this->input('roles', []))
            ->with('permissions:id,name')
            ->get()
            ->filter(fn (Role $role) => $role->permissions
                ->contains(fn ($permission) => $this->user()->cannot($permission->name)))
            ->pluck('name')
            ->all();

        if ($beyondReach !== []) {
            $validator->errors()->add(
                'roles',
                'You cannot assign a role that holds permissions you do not: '
                    .implode(', ', $beyondReach).'.',
            );
        }
    }

    protected function refusePermissionsBeyondReach(Validator $validator): void
    {
        $ungrantable = array_values(array_diff(
            (array) $this->input('permissions', []),
            $this->grantable(),
        ));

        if ($ungrantable !== []) {
            $validator->errors()->add(
                'permissions',
                'You cannot grant a permission you do not hold yourself: '
                    .implode(', ', $ungrantable).'.',
            );
        }
    }

    /**
     * A super admin passes every check through `Gate::before`, so the whole set is theirs.
     *
     * @return array<int, string>
     */
    public function grantable(): array
    {
        return array_values(array_filter(
            Permission::values(),
            fn (string $permission) => $this->user()->can($permission),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function roles(): array
    {
        return array_values(array_unique((array) $this->validated('roles')));
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return array_values(array_unique((array) $this->validated('permissions')));
    }
}
