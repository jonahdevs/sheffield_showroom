<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One account as its own screen edits it.
 *
 * `permissions` is deliberately only the direct grants - what the roles bring
 * is sent separately by `inherited()`, keyed by permission, so the form can
 * show the two apart instead of one merged list nobody can unpick.
 */
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
        /** Whether this is the signed-in account, which may not re-grant itself. */
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
     * Which roles hand this account each permission, keyed by permission.
     *
     * The super admin is spelled out rather than read off its permission rows:
     * `Gate::before` gives it every ability while its role may hold none, so a
     * query would report an account that can do anything as inheriting nothing
     * and the form would offer to grant it what it already has.
     *
     * The role is read as a name and a headline rather than through
     * `App\Models\Role`, because `config('permission.models.role')` hands this
     * relation spatie's own model — the subclass is only guaranteed where the
     * query names it.
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
