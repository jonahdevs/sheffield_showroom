<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Reward;
use App\Models\User;

class RewardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RewardsView->value);
    }

    public function view(User $user, Reward $reward): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RewardsCatalogueCreate->value);
    }

    # Safe on a reward campaigns already hold: nothing here is read through at
    # win time, so retuning the catalogue cannot move a promised deadline.
    public function update(User $user, Reward $reward): bool
    {
        return $user->can(Permission::RewardsCatalogueUpdate->value);
    }

    # `campaign_rewards.reward_id` is `restrictOnDelete`. Retire an attached reward
    # with `is_active = false` instead — it leaves running campaigns untouched.
    public function delete(User $user, Reward $reward): bool
    {
        return $user->can(Permission::RewardsCatalogueDelete->value)
            && ! $reward->attachments()->exists();
    }
}
