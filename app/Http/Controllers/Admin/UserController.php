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

/**
 * The accounts themselves: the name and address on one, the password behind
 * it, the roles it holds and the capabilities pinned to the person rather than
 * to the job they do.
 *
 * The Roles screen still owns the list of people — that is where you go to
 * find somebody, because a role means nothing until somebody is in one — and
 * it still staffs a role from there. This screen is the other direction: one
 * account, everything about it, without having to know which role to open
 * first. Both hand the same write to `RoleController::assign`.
 */
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
                /* The permission alone, with no subject to weigh it against:
                   the account does not exist yet, and everything it will be
                   handed is already capped at what the actor holds. */
                'permissions' => $request->user()->can(Permission::UsersPermissions->value),
                'password' => false,
            ],
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            /* `forceFill` because `email_verified_at` is not fillable, and it
               has to be set: an administrator typing the address in is the
               only confirmation a showroom account ever gets - there is nobody
               to click a link, and an unverified account cannot get past the
               `verified` middleware every screen here wears. */
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

            /* The request has already refused anything the actor does not
               hold and anything the roles above already carry, so what is
               left is genuinely pinned to the person. */
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

    /**
     * One screen for everything about an account.
     *
     * The permission matrix is the same one the Roles screen draws, handed the
     * same grantable set — reusing it means an administrator reads capabilities
     * the same way in both places. What is added is `inherited`: which roles
     * already carry each permission, so a tick can say where it came from
     * rather than leaving a direct grant and a role grant looking identical.
     *
     * Each of the three writes below it answers to its own permission, which
     * is why `can` is three flags rather than one: correcting a surname,
     * moving somebody between roles and pinning a capability to them are
     * different kinds of trust.
     */
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

        /* The same rule the profile screen holds: an address nobody has been
           shown to reach yet is not a verified one. */
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

    /**
     * Setting a password on somebody else's behalf.
     *
     * The password itself never leaves this method: it is not flashed, not
     * logged and not sent anywhere. The administrator already knows what they
     * typed, and anybody reading a log later has no business knowing it.
     */
    public function password(UserPasswordRequest $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user) {
            /* `forceFill` because the new remember token is guarded, and it
               has to move with the password - see `revokeExistingLogins`. */
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

    /**
     * The capabilities pinned to this account rather than to its roles.
     *
     * `syncPermissions` rather than give/revoke: the form posts the whole
     * direct set, so what is missing from it is meant to be gone. The request
     * has already refused anything the actor does not hold and anything a role
     * already carries.
     */
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
     * Turns an existing sign-in into a dead one.
     *
     * A password an administrator sets because an account is compromised is
     * only half a revocation while the session that worried them is still
     * open: the browser holding it never has to authenticate again. Rotating
     * the remember token above kills the "remember me" cookie; this clears
     * what is actually keeping them signed in.
     *
     * Only the database driver stores sessions somewhere addressable by user,
     * so a showroom running on cookies or a file store is left as it is rather
     * than being told a lie about having been secured.
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
     * What this person may hand out, mirroring `UserPermissionsRequest`: the
     * form only offers what the request would accept.
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
     * The roles an account can be put into. Every role is offered and the
     * ceiling is enforced on the way in, so the list matches what the Roles
     * screen shows rather than quietly hiding the ones out of reach.
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
     * What each role actually hands its holders, keyed by role name.
     *
     * The form needs this to work out what a tick would inherit before the
     * account exists to ask `UserFormData::inherited()` about - and, once it
     * does exist, to say what a role about to be added would bring. Super
     * admin is spelled out for the reason it is spelled out everywhere else:
     * `Gate::before` gives it every ability while its role may hold no
     * permission row, so reading its rows would report that it grants nothing.
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
