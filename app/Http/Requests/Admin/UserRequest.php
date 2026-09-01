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

# Roles and direct grants are settable here only on create, and only under the
# same checks UserRolesRequest and UserPermissionsRequest hold - creating an
# account would otherwise be the way around them.
class UserRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
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

        # Super admin is named, not read off its rows: `Gate::before` gives it
        # every ability while its role may hold none, so a subset test passes.
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

    # Nobody grants what they do not hold, and nothing the chosen roles already
    # carry may also be pinned: a duplicate grant outlives the role, invisibly.
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
