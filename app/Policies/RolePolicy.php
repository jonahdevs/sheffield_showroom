<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RolesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RolesCreate->value);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesUpdate->value) && $role->isEditable();
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesDelete->value) && $role->isEditable();
    }

    # Never to yourself: an account that can widen its own reach is a ceiling
    # that does not hold.
    public function assignTo(User $user, User $subject): bool
    {
        return $user->isNot($subject) && $user->can(Permission::RolesAssign->value);
    }
}
