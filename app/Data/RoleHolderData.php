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
    ) {}

    public static function fromModel(User $user, ?int $viewerId): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roles: $user->roles->pluck('name')->all(),
            is_self: $user->id === $viewerId,
        );
    }
}
