<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Purchase;
use App\Models\User;

/**
 * Who may record what somebody spent.
 *
 * Its own permission rather than riding along with `visits.update`, because a
 * purchase is the thing a reward is earned by: whoever can write one can
 * decide who qualifies for a promotion, and that is not the same trust as
 * writing up a visit.
 */
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

    /**
     * Editing an amount after the fact changes who qualified, so it stays
     * behind its own permission rather than following the right to create one.
     */
    public function update(User $user, Purchase $purchase): bool
    {
        return $user->can(Permission::PurchasesUpdate->value);
    }

    /**
     * A purchase that has already earned somebody a turn cannot be removed.
     *
     * The database says the same thing - `shuffle_sessions.purchase_id` is
     * restricted on delete - but a policy that refuses first turns a foreign
     * key error into a sentence somebody can act on.
     */
    public function delete(User $user, Purchase $purchase): bool
    {
        return $user->can(Permission::PurchasesDelete->value)
            && ! $purchase->shuffleSession()->exists();
    }
}
