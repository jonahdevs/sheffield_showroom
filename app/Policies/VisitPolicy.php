<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\Visit;

/**
 * Who may see which visits.
 *
 * The split the `Permission` enum promises: a manager holds `visits.view.any`
 * and sees the whole floor, a salesperson holds `visits.view.own` and sees
 * only what they logged. Every method that touches one visit goes through
 * `reaches()`, so editing and deleting inherit the same boundary as reading
 * rather than each having its own idea of it.
 */
class VisitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            Permission::VisitsViewAny->value,
            Permission::VisitsViewOwn->value,
        ]);
    }

    public function view(User $user, Visit $visit): bool
    {
        return $this->reaches($user, $visit);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::VisitsCreate->value);
    }

    /**
     * The permission to edit, and the visit being one this user may see at
     * all. A salesperson with `visits.update` may fix their own write-up, not
     * somebody else's.
     */
    public function update(User $user, Visit $visit): bool
    {
        return $user->can(Permission::VisitsUpdate->value)
            && $this->reaches($user, $visit);
    }

    public function delete(User $user, Visit $visit): bool
    {
        return $user->can(Permission::VisitsDelete->value)
            && $this->reaches($user, $visit);
    }

    public function export(User $user): bool
    {
        return $user->can(Permission::VisitsExport->value);
    }

    /**
     * Whether this visit is within the user's view at all.
     *
     * A visit whose logger has since been deleted has a null `created_by` and
     * belongs to nobody, so it stays with the managers.
     */
    private function reaches(User $user, Visit $visit): bool
    {
        if ($user->can(Permission::VisitsViewAny->value)) {
            return true;
        }

        return $user->can(Permission::VisitsViewOwn->value)
            && $visit->created_by !== null
            && $visit->created_by === $user->id;
    }
}
