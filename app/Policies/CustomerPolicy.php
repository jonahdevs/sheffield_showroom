<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CustomersViewAny->value);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersViewAny->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CustomersCreate->value);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersUpdate->value);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersDelete->value);
    }

    public function export(User $user): bool
    {
        return $user->can(Permission::CustomersExport->value);
    }

    # An import creates and updates, so it needs those permissions too — nobody
    # should reach through an upload what they may not do by hand.
    public function import(User $user): bool
    {
        return $user->can(Permission::CustomersImport->value)
            && $user->can(Permission::CustomersCreate->value)
            && $user->can(Permission::CustomersUpdate->value);
    }
}
