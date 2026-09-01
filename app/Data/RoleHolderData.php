<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

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
        public bool $is_self,
        # Counted here because a pinned grant appears nowhere else on this
        # screen: take the role away and the ability stays.
        public int $direct_permissions,
        # False where the account can do more than the viewer, so the row
        # offers no action the server would refuse.
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
