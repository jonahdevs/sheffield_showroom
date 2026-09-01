<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RewardCampaign;
use App\Models\User;

class RewardCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RewardsView->value);
    }

    public function view(User $user, RewardCampaign $campaign): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RewardsCampaignsCreate->value);
    }

    public function update(User $user, RewardCampaign $campaign): bool
    {
        return $user->can(Permission::RewardsCampaignsUpdate->value);
    }

    # Publishing writes the pool and cannot be taken back, so it answers to the
    # update permission rather than to the right to create a draft.
    public function publish(User $user, RewardCampaign $campaign): bool
    {
        return $this->update($user, $campaign) && ! $campaign->status->isPublished();
    }

    # Only ever a draft nobody used: once a campaign has a pool it has history,
    # and history is kept — stop it with `complete` instead.
    public function delete(User $user, RewardCampaign $campaign): bool
    {
        return $user->can(Permission::RewardsCampaignsDelete->value)
            && ! $campaign->status->isPublished();
    }
}
