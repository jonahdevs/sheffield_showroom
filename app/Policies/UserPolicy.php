<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Every write is checked twice: for the capability, and for whether the actor's reach
 * covers the subject. Without the second half `users.update` is an escalation - editing
 * an account moves the address its password reset lands at.
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
        return $user->can(Permission::UsersUpdate->value) && $this->reaches($user, $subject);
    }

    /**
     * Never your own: that goes through the security screen, where the current password
     * has to be typed first. Skipping it here turns a borrowed laptop into a takeover.
     */
    public function updatePassword(User $user, User $subject): bool
    {
        return $user->isNot($subject) && $this->update($user, $subject);
    }

    /**
     * Never to yourself - an account that edits its own grants can quietly drop the
     * permission that was meant to hold it in place.
     */
    public function managePermissions(User $user, User $subject): bool
    {
        return $user->isNot($subject)
            && $user->can(Permission::UsersPermissions->value)
            && $this->reaches($user, $subject);
    }

    /**
     * Whether everything the subject can do, the actor can do too.
     *
     * Super admin is named, never derived: `Gate::before` hands that role every ability
     * while its role row may hold no permission at all, so a subset test against the
     * database answers "grants nothing" and lets anybody with `users.update` take it over.
     */
    private function reaches(User $user, User $subject): bool
    {
        if ($subject->hasRole(Role::SUPER_ADMIN)) {
            return $user->hasRole(Role::SUPER_ADMIN);
        }

        return $subject->getAllPermissions()
            ->every(fn ($permission) => $user->can($permission->name));
    }
}
