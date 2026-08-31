<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ShuffleResult;
use App\Models\User;

/**
 * Who may hand a won reward over.
 *
 * Kept apart from `rewards.shuffle` because the two happen weeks apart and
 * often at different desks: one is the person at the counter on the day of the
 * sale, the other is whoever is on when the customer comes back for their free
 * installation.
 */
class ShuffleResultPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RewardsView->value);
    }

    public function view(User $user, ShuffleResult $result): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Whether the reward may be handed over now.
     *
     * The permission is only half of it. `isRedeemable()` reads the date as
     * well as the status, so a reward whose window closed before anything
     * swept it is refused here rather than at the service - which means the
     * screen can grey the button out instead of accepting a click and
     * explaining afterwards.
     */
    public function redeem(User $user, ShuffleResult $result): bool
    {
        return $user->can(Permission::RewardsRedeem->value) && $result->isRedeemable();
    }
}
