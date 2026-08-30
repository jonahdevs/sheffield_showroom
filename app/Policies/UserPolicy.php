<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * The accounts themselves, as opposed to the roles they hold.
 *
 * Editing an account is where its email address is set, which is why the
 * profile screen no longer offers one: the address is what somebody signs in
 * with, so it belongs to whoever administers access rather than to the person
 * holding the account.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersViewAny->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::UsersCreate->value);
    }

    public function update(User $user, User $subject): bool
    {
        return $user->can(Permission::UsersUpdate->value);
    }
}
