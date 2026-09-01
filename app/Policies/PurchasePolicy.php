<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PurchasesViewAny->value);
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PurchasesCreate->value);
    }

    public function update(User $user, Purchase $purchase): bool
    {
        return $user->can(Permission::PurchasesUpdate->value);
    }

    # `shuffle_sessions.purchase_id` is `restrictOnDelete`; refusing here first
    # turns the integrity error into a sentence somebody can act on.
    public function delete(User $user, Purchase $purchase): bool
    {
        return $user->can(Permission::PurchasesDelete->value)
            && ! $purchase->shuffleSession()->exists();
    }
}
