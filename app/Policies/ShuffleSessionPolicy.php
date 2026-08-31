<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\ShuffleSessionStatus;
use App\Models\ShuffleSession;
use App\Models\User;

/**
 * Who may give a customer their turn, and run it for them.
 *
 * Nothing here governs the customer's own shuffle. That answers to the token
 * in the QR code and to nothing else - a customer has no account, and
 * requiring one would be the shortest way to stop anybody ever using this.
 */
class ShuffleSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RewardsView->value);
    }

    public function view(User $user, ShuffleSession $session): bool
    {
        return $this->viewAny($user);
    }

    /** Minting a turn against a qualifying purchase. */
    public function create(User $user): bool
    {
        return $user->can(Permission::RewardsShuffle->value);
    }

    /**
     * Running the shuffle from the showroom screen when the customer cannot
     * scan.
     *
     * The same permission as minting, because it is the same conversation at
     * the same counter, and the same service underneath - there is no second
     * way of choosing a reward.
     */
    public function run(User $user, ShuffleSession $session): bool
    {
        return $user->can(Permission::RewardsShuffle->value);
    }

    /**
     * Taking a turn back before it is used - a sale reversed, or a code shown
     * to the wrong person. A turn already shuffled cannot be cancelled; the
     * reward behind it was won.
     */
    public function cancel(User $user, ShuffleSession $session): bool
    {
        return $user->can(Permission::RewardsShuffle->value)
            && $session->status === ShuffleSessionStatus::Pending;
    }
}
