<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\PermissionGroupData;
use App\Data\RoleData;
use App\Data\UserFormData;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The accounts, opened from the Users panel on the Roles screen.
 *
 * There is no list here: the Roles screen already lists everybody with the
 * roles they hold, and a second table of the same people would be one to keep
 * in step for nothing. This is the form behind that list's two links.
 */
class UserController extends Controller
{
    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('admin/users/Form', [
            'user' => null,
            'roles' => $this->roleOptions(),
            'matrix' => PermissionGroupData::matrix($this->grantable($request)),
            /* No subject to check against yet, so the raw permission. Nobody
               can create themselves, which is the case `assignTo` guards. */
            'can_grant' => $request->user()->can(Permission::RolesAssign->value),
        ]);
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('update', $user);

        $user->load(['roles:id,name', 'permissions:id,name']);

        return Inertia::render('admin/users/Form', [
            'user' => UserFormData::fromModel($user, $request->user()->id),
            'roles' => $this->roleOptions(),
            'matrix' => PermissionGroupData::matrix($this->grantable($request)),
            'can_grant' => $request->user()->can('assignTo', [Role::class, $user]),
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::query()->create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => $request->validated('password'),
            ]);

            $this->grant($request, $user);

            return $user;
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been added.', ['name' => $user->name]),
        ]);

        return to_route('admin.roles.index');
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user) {
            $user->forceFill([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
            ]);

            /* Left blank on the form means "leave it as it is", not "clear
               it": an administrator editing somebody's email should not have
               to know their password to do it. */
            $password = $request->validated('password');

            if (filled($password)) {
                $user->password = $password;
            }

            $user->save();

            $this->grant($request, $user);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been saved.', ['name' => $user->name]),
        ]);

        return to_route('admin.roles.index');
    }

    /**
     * What the account may do: the roles it holds, and anything granted to it
     * alone on top of them.
     *
     * Both are skipped for a sender who may not hand them out, so an
     * administrator without `roles.assign` can still correct a name or an
     * address without silently clearing what somebody holds.
     */
    private function grant(UserRequest $request, User $user): void
    {
        if (! $request->mayGrant()) {
            return;
        }

        /* Absent is not empty. The form always sends both lists, so a missing
           one is a caller that never meant to touch them - and reading it as
           "none" would strip an account bare on a request that only set a
           name. */
        if ($request->has('roles')) {
            $user->syncRoles($request->roles());
        }

        /* Direct grants only. `syncPermissions` on a user leaves whatever its
           roles carry untouched. */
        if ($request->has('permissions')) {
            $user->syncPermissions($request->permissions());
        }
    }

    /**
     * Every role, for the form's picker. What may actually be handed out is
     * `UserRequest`'s business - a role holding a permission the sender lacks
     * is refused there rather than hidden here, so the screen shows the same
     * list to everybody who can see it.
     *
     * @return array<int, RoleData>
     */
    private function roleOptions(): array
    {
        return Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(RoleData::fromModel(...))
            ->all();
    }

    /**
     * The permissions this viewer may grant directly, which is the set they
     * hold themselves.
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
}
