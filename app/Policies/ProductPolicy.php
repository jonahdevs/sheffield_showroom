<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProductsViewAny->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProductsCreate->value);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can(Permission::ProductsUpdate->value);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can(Permission::ProductsDelete->value);
    }
}
