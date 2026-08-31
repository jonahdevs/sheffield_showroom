<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Who may look after somebody else's account.
 *
 * Every write here is checked twice: once for the capability itself, and once
 * for whether the actor's own reach covers the person they are pointing it at.
 * Without the second half `users.update` is an escalation rather than an
 * administrative chore - editing an account means being able to change the
 * address a password reset lands at, so anyone who can edit the super admin
 * effectively holds the super admin.
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

    /**
     * Editing your own account from here is fine. It is not the same change
     * the profile screen offers - that one is a name and nothing else, because
     * the address an account signs in at is not the account's to move. This
     * door is the one that moves it, and it is already behind `users.update`.
     */
    public function update(User $user, User $subject): bool
    {
        return $user->can(Permission::UsersUpdate->value) && $this->reaches($user, $subject);
    }

    /**
     * Setting a password for somebody, which is not the same as changing your
     * own. Your own goes through the security screen, where the current
     * password has to be typed first; letting an administrator skip that on
     * their own account would turn a borrowed unlocked laptop into a takeover.
     */
    public function updatePassword(User $user, User $subject): bool
    {
        return $user->isNot($subject) && $this->update($user, $subject);
    }

    /**
     * Granting capabilities straight to an account, past any role.
     *
     * Never to yourself. Not because the ceiling below would let anything
     * through - it would not - but because an account that edits its own
     * grants can quietly drop the one permission that was meant to hold it in
     * place, and there is no reason to point this at yourself in the first
     * place.
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
     * The super admin is named rather than derived: `Gate::before` hands that
     * role every ability without it holding a single permission row, so asking
     * the database what it can do answers "nothing" and would let anybody with
     * `users.update` take the account over.
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
