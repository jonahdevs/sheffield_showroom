<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Handing out what somebody may do, wherever the application asks for it:
 * roles onto an account, and permissions onto a role or straight onto an
 * account.
 *
 * The ceiling is what is worth sharing. You cannot grant a permission you do
 * not hold yourself, and you cannot hand out a role holding one - or assigning
 * becomes the way around the grant check. Two copies of that rule would drift,
 * and the copy that drifts is a hole.
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

    /**
     * Refuse any role carrying a permission the assigner does not hold.
     */
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

    /**
     * Refuse any permission the grantor does not hold themselves.
     */
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
     * What this person may hand out. A super admin passes every check through
     * `Gate::before`, so the whole set is theirs to give.
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
