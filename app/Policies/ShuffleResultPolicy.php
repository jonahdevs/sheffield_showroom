<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ShuffleResult;
use App\Models\User;

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

    # `isRedeemable()` reads the date as well as the status, so a result whose
    # window closed before `rewards:expire` swept it is already refused.
    public function redeem(User $user, ShuffleResult $result): bool
    {
        return $user->can(Permission::RewardsRedeem->value) && $result->isRedeemable();
    }
}
