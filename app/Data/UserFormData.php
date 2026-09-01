<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# `permissions` is the direct grants only; what the roles bring comes
# separately from `inherited()`. Do not merge the two into one list.
#[TypeScript(location: ['App', 'Data'])]
class UserFormData extends Data
{
    /**
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public array $roles,
        public array $permissions,
        public bool $is_self,
    ) {}

    public static function fromModel(User $user, ?int $viewerId): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roles: $user->roles->pluck('name')->all(),
            permissions: $user->permissions->pluck('name')->all(),
            is_self: $user->id === $viewerId,
        );
    }

    /**
     * Super admin is named, never read off its permission rows: `Gate::before`
     * gives it every ability while its role row may hold none, so a query
     * would report it as inheriting nothing and the form would offer to grant
     * what it already has.
     *
     * @return array<string, array<int, string>>
     */
    public static function inherited(User $user): array
    {
        $inherited = [];

        foreach ($user->roles as $role) {
            $granted = $role->name === Role::SUPER_ADMIN
                ? Permission::values()
                : $role->permissions->pluck('name')->all();

            foreach ($granted as $permission) {
                $inherited[$permission][] = Str::headline($role->name);
            }
        }

        return array_map(
            fn (array $roles) => array_values(array_unique($roles)),
            $inherited,
        );
    }
}
