<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating an account and editing the account part of one: who it belongs to
 * and where it is reachable. What it may do is two other requests.
 *
 * A new account is given its roles and its direct permissions here rather than
 * on a second screen. An account with no role keeps its login and loses every
 * ability on it, which reads as a broken account rather than a new one - the
 * same reason `RoleController::destroy` refuses to strand a role's members.
 * Both sets answer to the checks their own screens hold, read at the moment
 * the account is born, because creating one would otherwise be the shortest
 * way around them.
 */
class UserRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * The roles named on the way in, read once: both checks below want them
     * and each read is a query.
     *
     * @var Collection<int, Role>|null
     */
    private ?Collection $chosenRoles = null;

    public function authorize(): bool
    {
        $subject = $this->subject();

        return $subject === null
            ? $this->user()->can('create', User::class)
            : $this->user()->can('update', $subject);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $subject = $this->subject();

        $rules = [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($subject?->id),
        ];

        /* Roles, direct permissions and a password belong to creating an
           account. Editing one changes each through its own action, behind its
           own check, so none of them can be moved by a stray field on the
           details form. */
        if ($subject === null) {
            $rules['password'] = $this->passwordRules();
            $rules['roles'] = ['present', 'array'];
            $rules['roles.*'] = ['string', Rule::exists('roles', 'name')];
            $rules['permissions'] = ['sometimes', 'array'];
            $rules['permissions.*'] = ['string', Rule::in(Permission::values())];
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->validateRoles(...),
            $this->validateDirectPermissions(...),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function roles(): array
    {
        return array_values(array_unique((array) $this->input('roles', [])));
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return array_values(array_unique((array) $this->input('permissions', [])));
    }

    public function subject(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }

    /**
     * The ceiling `UserRolesRequest` holds, read at the moment the account is
     * born: creating a user would otherwise be the way around the check that
     * assigning one enforces.
     */
    private function validateRoles(Validator $validator): void
    {
        $roles = $this->roles();

        if ($roles === [] || $this->subject() !== null) {
            return;
        }

        if ($this->user()->cannot(Permission::RolesAssign->value)) {
            $validator->errors()->add(
                'roles',
                'You cannot put somebody in a role. Create the account and ask an administrator to staff it.',
            );

            return;
        }

        /* Super admin is named rather than read off its rows, because
           `Gate::before` gives it every ability while its role may hold none -
           a subset test against an empty list passes. */
        $beyondReach = $this->chosenRoles()
            ->filter(fn (Role $role) => $role->isSuperAdmin()
                ? ! $this->user()->hasRole(Role::SUPER_ADMIN)
                : $role->permissions->contains(
                    fn ($permission) => $this->user()->cannot($permission->name),
                ))
            ->pluck('name')
            ->all();

        if ($beyondReach !== []) {
            $validator->errors()->add(
                'roles',
                'You cannot assign a role that holds permissions you do not: '
                    .implode(', ', $beyondReach).'.',
            );
        }
    }

    /**
     * The two rules `UserPermissionsRequest` holds, for the same reasons:
     * nobody hands out what they do not hold themselves, and nothing a role on
     * this very form already carries may also be pinned to the account - a
     * duplicate grant outlives the role and says so nowhere.
     */
    private function validateDirectPermissions(Validator $validator): void
    {
        $wanted = $this->permissions();

        if ($wanted === [] || $this->subject() !== null) {
            return;
        }

        if ($this->user()->cannot(Permission::UsersPermissions->value)) {
            $validator->errors()->add(
                'permissions',
                'You cannot pin a capability to an account. Create it, and ask somebody who can.',
            );

            return;
        }

        $ungrantable = array_values(array_diff($wanted, $this->grantable()));

        if ($ungrantable !== []) {
            $validator->errors()->add(
                'permissions',
                'You cannot grant a permission you do not hold yourself: '
                    .implode(', ', $ungrantable).'.',
            );
        }

        $inherited = array_values(array_intersect($wanted, $this->rolePermissions()));

        if ($inherited !== []) {
            $validator->errors()->add(
                'permissions',
                'A role already grants this, so pinning it to the account would outlive the role: '
                    .implode(', ', $inherited).'.',
            );
        }
    }

    /**
     * What this person may hand out. A super admin passes every check through
     * `Gate::before`, so the whole set is theirs to give.
     *
     * @return array<int, string>
     */
    private function grantable(): array
    {
        return array_values(array_filter(
            Permission::values(),
            fn (string $permission) => $this->user()->can($permission),
        ));
    }

    /**
     * Everything the roles on this form would hand the new account.
     *
     * @return array<int, string>
     */
    private function rolePermissions(): array
    {
        return $this->chosenRoles()
            ->flatMap(fn (Role $role) => $role->isSuperAdmin()
                ? Permission::values()
                : $role->permissions->pluck('name')->all())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Role>
     */
    private function chosenRoles(): Collection
    {
        return $this->chosenRoles ??= Role::query()
            ->whereIn('name', $this->roles())
            ->with('permissions:id,name')
            ->get();
    }
}
