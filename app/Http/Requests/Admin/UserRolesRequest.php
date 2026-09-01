<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

# A role you could not have built is a role you cannot hand out, or assigning
# becomes the way around RoleRequest's grant check.
class UserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assignTo', [Role::class, $this->subject()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                # Super admin is named, not read off its rows: `Gate::before`
                # gives it every ability while its role may hold no permission
                # row, so a subset test says it grants nothing.
                $beyondReach = Role::query()
                    ->whereIn('name', (array) $this->input('roles', []))
                    ->with('permissions:id,name')
                    ->get()
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
            },
        ];
    }

    /**
     * @return array<int, string>
     */
    public function roles(): array
    {
        return array_values(array_unique((array) $this->validated('roles')));
    }

    public function subject(): User
    {
        $user = $this->route('user');

        abort_unless($user instanceof User, 404);

        return $user;
    }
}
