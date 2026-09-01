<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\PermissionGroupData;
use App\Data\PermissionRowData;
use App\Data\RoleData;
use App\Data\RoleHolderData;
use App\Data\UserFormData;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Requests\Admin\UserRolesRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Http\PageSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    private const UNASSIGNED = 'none';

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        $viewer = $request->user();

        $roles = Role::query()
            ->with(['permissions:id,name', 'users:id,name'])
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        # Its own permission: shaping roles does not imply opening the accounts holding them.
        $canViewUsers = $viewer->can('viewAny', User::class);

        return Inertia::render('admin/roles/Index', [
            'roles' => $roles->map(RoleData::fromModel(...))->all(),
            'holders' => $canViewUsers ? $this->holders($request) : null,
            'filters' => $this->holderFilters($request),
            'page_sizes' => PageSize::OPTIONS,
            'can' => [
                'create' => $viewer->can('create', Role::class),
                'update' => $viewer->can(Permission::RolesUpdate->value),
                'delete' => $viewer->can(Permission::RolesDelete->value),
                'assign' => $viewer->can(Permission::RolesAssign->value),
                'view_users' => $canViewUsers,
                'create_users' => $viewer->can('create', User::class),
                # Not the whole answer: reach also depends on what the target account can
                # do, so each row carries its own `is_manageable`.
                'update_users' => $viewer->can(Permission::UsersUpdate->value),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('admin/roles/Form', [
            'role' => null,
            'matrix' => PermissionGroupData::matrix($this->grantable($request)),
            'read_only' => false,
        ]);
    }

    public function edit(Request $request, Role $role): Response
    {
        $this->authorize('viewAny', Role::class);

        $role->load('permissions:id,name');

        return Inertia::render('admin/roles/Form', [
            'role' => RoleData::fromModel($role),
            'matrix' => PermissionGroupData::matrix($this->grantable($request)),
            'read_only' => $role->is_system || $request->user()->cannot('update', $role),
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = DB::transaction(function () use ($request) {
            $role = Role::query()->create([
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'guard_name' => config('auth.defaults.guard'),
                'is_system' => false,
            ]);

            $role->syncPermissions($request->permissions());

            return $role;
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The :role role has been created.', ['role' => $role->name]),
        ]);

        return to_route('admin.roles.index');
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        DB::transaction(function () use ($request, $role) {
            $role->forceFill([
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
            ])->save();

            $role->syncPermissions($request->permissions());
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The :role role has been saved.', ['role' => $role->name]),
        ]);

        return to_route('admin.roles.index');
    }

    # Holders are moved, never stranded: an account left with no role keeps its login and
    # loses every ability.
    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $fallback = null;

        if ($role->users()->exists()) {
            $fallback = Role::query()
                ->where('name', $request->string('fallback')->toString())
                ->whereKeyNot($role->id)
                ->first();

            if ($fallback === null) {
                return back()->withErrors([
                    'fallback' => 'Choose the role its members should move to.',
                ]);
            }
        }

        DB::transaction(function () use ($role, $fallback) {
            if ($fallback !== null) {
                $role->users()->each(function (User $holder) use ($role, $fallback) {
                    $holder->removeRole($role);
                    $holder->assignRole($fallback);
                });
            }

            $role->delete();
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The :role role has been deleted.', ['role' => $role->name]),
        ]);

        return back();
    }

    /**
     * Trims the direct grants against the new roles so the two sets never overlap. A
     * capability held twice survives the role being revoked, and nothing on this screen
     * would say why.
     */
    public function assign(UserRolesRequest $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user) {
            $user->syncRoles($request->roles());

            $user->load(['roles.permissions:id,name', 'permissions:id,name']);

            $redundant = array_intersect(
                $user->permissions->pluck('name')->all(),
                array_keys(UserFormData::inherited($user)),
            );

            if ($redundant !== []) {
                $user->syncPermissions(array_diff(
                    $user->permissions->pluck('name')->all(),
                    $redundant,
                ));
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Roles updated for :name.', ['name' => $user->name]),
        ]);

        return back();
    }

    # The list is the enum, not a query, so this pages in memory.
    public function permissions(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        $search = $request->string('search')->trim()->toString();
        $group = $request->string('group')->toString();

        $rows = collect(PermissionRowData::forRoles(
            Role::query()->with('permissions:id,name')->orderBy('name')->get(),
            User::query()
                ->has('permissions')
                ->with('permissions:id,name')
                ->orderBy('name')
                ->get(),
        ))
            ->when($group !== '', fn ($all) => $all->where('group', $group))
            ->when($search !== '', fn ($all) => $all->filter(
                fn (PermissionRowData $row) => Str::contains($row->value, $search, ignoreCase: true)
                    || Str::contains($row->label, $search, ignoreCase: true)
                    || Str::contains($row->group_label, $search, ignoreCase: true),
            ))
            ->values();

        $perPage = PageSize::from($request);
        $page = max(1, $request->integer('page', 1));

        return Inertia::render('admin/Permissions', [
            'permissions' => new LengthAwarePaginator(
                items: $rows->forPage($page, $perPage)->values(),
                total: $rows->count(),
                perPage: $perPage,
                currentPage: $page,
                options: ['path' => $request->url(), 'query' => $request->query()],
            ),
            'groups' => array_map(
                fn (string $group) => [
                    'value' => $group,
                    'label' => Permission::groupLabel($group),
                ],
                array_keys(Permission::grouped()),
            ),
            'page_sizes' => PageSize::OPTIONS,
            'filters' => ['search' => $search, 'group' => $group],
        ]);
    }

    /**
     * Mirrors `RoleRequest::grantable()` - the form only offers what the request accepts.
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
     * @return LengthAwarePaginator<int, RoleHolderData>
     */
    private function holders(Request $request): LengthAwarePaginator
    {
        $filters = $this->holderFilters($request);
        $viewer = $request->user();

        return User::query()
            # The permissions must come with the rows: `RoleHolderData` asks whether the
            # viewer's reach covers each account, which reads what that account can do.
            ->with(['roles:id,name', 'roles.permissions:id,name', 'permissions:id,name'])
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('email', 'like', "%{$filters['search']}%"),
            ))
            ->when($filters['role'] === self::UNASSIGNED, fn (Builder $query) => $query->doesntHave('roles'))
            ->when(
                $filters['role'] !== '' && $filters['role'] !== self::UNASSIGNED,
                fn (Builder $query) => $query->whereHas(
                    'roles',
                    fn (Builder $inner) => $inner->where('name', $filters['role']),
                ),
            )
            ->orderBy('name')
            ->paginate(PageSize::from($request))
            ->withQueryString()
            ->through(fn (User $user) => RoleHolderData::fromModel($user, $viewer));
    }

    /**
     * @return array{search: string, role: string}
     */
    private function holderFilters(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->toString(),
            'role' => $request->string('role')->toString(),
        ];
    }
}
