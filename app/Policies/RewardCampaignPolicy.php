<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RewardCampaign;
use App\Models\User;

/**
 * Who may shape a promotion.
 *
 * Reading is split from writing because they are different jobs: a manager
 * watching how many rewards are left is not necessarily the person who decides
 * how many there were.
 */
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

    /**
     * Editing the campaign itself.
     *
     * A published campaign is still editable - its name, its dates and its
     * ceiling are all fair game - but its reward quantities are not, and
     * `RewardCampaignRequest` is what refuses those. Publishing is what turns
     * numbers into inventory; nothing here can undo that.
     */
    public function update(User $user, RewardCampaign $campaign): bool
    {
        return $user->can(Permission::RewardsCampaignsUpdate->value);
    }

    /**
     * Writing the pool and opening the doors. The most consequential thing on
     * this screen and the only one that cannot be taken back, so it answers to
     * the update permission rather than to the right to create a draft.
     */
    public function publish(User $user, RewardCampaign $campaign): bool
    {
        return $this->update($user, $campaign) && ! $campaign->status->isPublished();
    }

    /**
     * Deleting is only ever a way to tidy away a draft nobody used. Once a
     * campaign has a pool it has history, and history is kept - stop it with
     * `complete` instead.
     */
    public function delete(User $user, RewardCampaign $campaign): bool
    {
        return $user->can(Permission::RewardsCampaignsDelete->value)
            && ! $campaign->status->isPublished();
    }
}
