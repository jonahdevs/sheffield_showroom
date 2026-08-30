<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An account as its own form reads it. No password: what is on file is a hash
 * nobody can put back in a box, so the field starts empty and only a typed
 * value replaces it.
 */
#[TypeScript(location: ['App', 'Data'])]
class UserFormData extends Data
{
    /**
     * @param  array<int, string>  $roles
     * @param  array<int, int>  $role_ids
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public array $roles,
        /** The picker works in ids; the wire format is names. */
        public array $role_ids,
        /** Granted to this account alone, on top of whatever its roles carry. */
        public array $permissions,
        /** Whether this is the signed-in account, which may not re-role itself. */
        public bool $is_self,
    ) {}

    public static function fromModel(User $user, ?int $viewerId): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roles: $user->roles->pluck('name')->all(),
            role_ids: $user->roles->pluck('id')->all(),
            permissions: $user->getDirectPermissions()->pluck('name')->all(),
            is_self: $user->id === $viewerId,
        );
    }
}
