<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\ShuffleSessionStatus;
use App\Models\ShuffleSession;
use App\Models\User;

# Nothing here governs the customer's own shuffle: that answers to the token in
# the QR code and to nothing else, because a customer has no account.
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

    public function create(User $user): bool
    {
        return $user->can(Permission::RewardsShuffle->value);
    }

    public function run(User $user, ShuffleSession $session): bool
    {
        return $user->can(Permission::RewardsShuffle->value);
    }

    # A turn already shuffled cannot be cancelled: the reward behind it was won.
    public function cancel(User $user, ShuffleSession $session): bool
    {
        return $user->can(Permission::RewardsShuffle->value)
            && $session->status === ShuffleSessionStatus::Pending;
    }
}
