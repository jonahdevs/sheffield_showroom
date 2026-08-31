<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A user as the Roles screen lists them: who they are and what they hold.
 *
 * No initials here — `useInitials` already derives them on the client, and two
 * implementations of the same rule drift.
 */
#[TypeScript(location: ['App', 'Data'])]
class RoleHolderData extends Data
{
    /**
     * @param  array<int, string>  $roles
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public array $roles,
        /** Whether this row is the signed-in user, who may not re-role themselves. */
        public bool $is_self,
        /**
         * How many capabilities are pinned to this account rather than to a
         * role it holds. Counted in the list because a direct grant appears
         * nowhere on the Roles screen otherwise: take the role away and the
         * ability stays, with nothing on this page to say so.
         */
        public int $direct_permissions,
        /**
         * Whether the viewer's own reach covers this account. False for
         * somebody who can do more than the viewer can, so the row offers no
         * action the server would refuse.
         */
        public bool $is_manageable,
    ) {}

    public static function fromModel(User $user, ?User $viewer): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roles: $user->roles->pluck('name')->all(),
            is_self: $user->id === $viewer?->id,
            direct_permissions: $user->permissions->count(),
            is_manageable: $viewer !== null && $viewer->can('update', $user),
        );
    }
}
