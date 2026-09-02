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

    # Turns, not status: a campaign nobody ever shuffled holds nothing but
    # inventory and is disposable whatever state it is in, while one with a
    # session behind it is history and is kept — stop it with `complete`
    # instead. `shuffle_sessions.campaign_id` is `restrictOnDelete`, so the
    # database draws the same line under this.
    public function delete(User $user, RewardCampaign $campaign): bool
    {
        return $user->can(Permission::RewardsCampaignsDelete->value)
            && ! $campaign->sessions()->exists();
    }
}
