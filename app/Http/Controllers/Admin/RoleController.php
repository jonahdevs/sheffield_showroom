<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\PermissionGroupData;
use App\Data\PermissionRowData;
use App\Data\RoleData;
use App\Data\RoleHolderData;
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

/**
 * Roles, the people holding them, and the permissions behind them.
 */
class RoleController extends Controller
{
    /** The role filter's value for an account holding none. */
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

        return Inertia::render('admin/roles/Index', [
            'roles' => $roles->map(RoleData::fromModel(...))->all(),
            'holders' => $this->holders($request),
            'filters' => $this->holderFilters($request),
            'page_sizes' => PageSize::OPTIONS,
            'can' => [
                'create' => $viewer->can('create', Role::class),
                'update' => $viewer->can(Permission::RolesUpdate->value),
                'delete' => $viewer->can(Permission::RolesDelete->value),
                'assign' => $viewer->can(Permission::RolesAssign->value),
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

    /**
     * The same screen serves reading and writing. A role the application ships
     * with opens read-only rather than being hidden: the checks it gates are
     * written into the code, so its permissions are worth seeing and not worth
     * reshaping — which is what the policy would refuse anyway.
     */
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

        /* The form is a page of its own, so `back()` would land on the form
           that was just submitted. */
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

    /**
     * Holders are moved rather than stranded. A user left with no role at all
     * would keep their account and lose every ability on it, which reads as a
     * broken account rather than a deleted role.
     */
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
     * Setting the roles one user holds, from the Roles screen's Users panel.
     */
    public function assign(UserRolesRequest $request, User $user): RedirectResponse
    {
        $user->syncRoles($request->roles());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Roles updated for :name.', ['name' => $user->name]),
        ]);

        return back();
    }

    /**
     * The capabilities themselves, read-only: they are declared in code and
     * synced by `permissions:sync`, so the list is the enum rather than a query.
     * Paginated in memory for the same reason — there is no table to page over.
     */
    public function permissions(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        $search = $request->string('search')->trim()->toString();
        $group = $request->string('group')->toString();

        $rows = collect(PermissionRowData::forRoles(
            Role::query()->with('permissions:id,name')->orderBy('name')->get(),
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
     * What this person may hand out, mirroring `RoleRequest::grantable()`: the
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
     * The accounts these roles are handed to. A user who has not been given
     * one yet is listed precisely so they can be.
     *
     * @return LengthAwarePaginator<int, RoleHolderData>
     */
    private function holders(Request $request): LengthAwarePaginator
    {
        $filters = $this->holderFilters($request);
        $viewerId = $request->user()->id;

        return User::query()
            ->with('roles:id,name')
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
            ->through(fn (User $user) => RoleHolderData::fromModel($user, $viewerId));
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
