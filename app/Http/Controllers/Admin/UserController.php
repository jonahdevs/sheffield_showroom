<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\PermissionGroupData;
use App\Data\RoleData;
use App\Data\UserFormData;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserPasswordRequest;
use App\Http\Requests\Admin\UserPermissionsRequest;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        $roles = $this->assignableRoles();

        return Inertia::render('admin/users/Form', [
            'user' => null,
            'matrix' => PermissionGroupData::matrix($this->grantable($request)),
            'inherited' => (object) [],
            'roles' => $roles->map(RoleData::fromModel(...))->all(),
            'role_grants' => $this->roleGrants($roles),
            'can' => [
                'assign_roles' => $request->user()->can(Permission::RolesAssign->value),
                # The bare permission, not the policy: no account exists to weigh it against yet.
                'permissions' => $request->user()->can(Permission::UsersPermissions->value),
                'password' => false,
            ],
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            # `forceFill` because `email_verified_at` is guarded and must be set: nobody clicks
            # a link here, and an unverified account cannot pass the `verified` middleware.
            $user = (new User)->forceFill([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => Hash::make($request->validated('password')),
                'email_verified_at' => now(),
            ]);

            $user->save();

            if ($request->roles() !== []) {
                $user->syncRoles($request->roles());
            }

            if ($request->permissions() !== []) {
                $user->syncPermissions($request->permissions());
            }

            return $user;
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been added.', ['name' => $user->name]),
        ]);

        return to_route('admin.roles.index');
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('update', $user);

        $user->load(['roles.permissions:id,name', 'permissions:id,name']);

        $viewer = $request->user();

        $roles = $this->assignableRoles();

        return Inertia::render('admin/users/Form', [
            'user' => UserFormData::fromModel($user, $viewer->id),
            'matrix' => PermissionGroupData::matrix($this->grantable($request)),
            'inherited' => UserFormData::inherited($user),
            'roles' => $roles->map(RoleData::fromModel(...))->all(),
            'role_grants' => $this->roleGrants($roles),
            'can' => [
                'assign_roles' => $viewer->can('assignTo', [Role::class, $user]),
                'permissions' => $viewer->can('managePermissions', $user),
                'password' => $viewer->can('updatePassword', $user),
            ],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->safe()->only(['name', 'email']));

        # An address nobody has been shown to reach is not a verified one.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been saved.', ['name' => $user->name]),
        ]);

        return to_route('admin.users.edit', $user);
    }

    # Never flash or log the password: the administrator already knows what they typed.
    public function password(UserPasswordRequest $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user) {
            # `forceFill`: the remember token is guarded and must rotate with the password.
            $user->forceFill([
                'password' => Hash::make($request->validated('password')),
                'remember_token' => Str::random(60),
            ])->save();

            $this->revokeExistingLogins($user);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('A new password has been set for :name, and they have been signed out everywhere. Give it to them in person.', ['name' => $user->name]),
        ]);

        return to_route('admin.users.edit', $user);
    }

    # `syncPermissions`, not give/revoke: the form posts the whole direct set, so what is
    # missing is meant to be gone.
    public function permissions(UserPermissionsRequest $request, User $user): RedirectResponse
    {
        $user->syncPermissions($request->permissions());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $request->permissions() === []
                ? __('Every direct permission has been taken off :name.', ['name' => $user->name])
                : __('Direct permissions updated for :name.', ['name' => $user->name]),
        ]);

        return to_route('admin.users.edit', $user);
    }

    /**
     * Rotating the remember token only kills the "remember me" cookie; an open session
     * never has to authenticate again, so a password reset without this is half a
     * revocation. Only the database driver stores sessions addressably by user — under
     * cookie or file sessions this is a no-op rather than a false promise.
     */
    private function revokeExistingLogins(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * Mirrors `UserPermissionsRequest` — the form only offers what the request accepts.
     *
     * @return array<int, string>
     */
    private function grantable(Request $request): array
    {
        return array_values(array_filter(
            Permission::values(),
            fn (string $permission) => $request->user()->can($permission),
        ));
    }

    /**
     * Every role is offered; the ceiling is enforced on the way in, not by hiding rows.
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(): Collection
    {
        return Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    /**
     * Super admin is named rather than read from its rows: `Gate::before` gives it every
     * ability while its role may hold no permission row at all.
     *
     * @param  Collection<int, Role>  $roles
     * @return array<string, array<int, string>>
     */
    private function roleGrants(Collection $roles): array
    {
        return $roles
            ->mapWithKeys(fn (Role $role) => [
                $role->name => $role->isSuperAdmin()
                    ? Permission::values()
                    : $role->permissions->pluck('name')->all(),
            ])
            ->all();
    }
}
