<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Setting which roles a user holds. The same ceiling as `RoleRequest`, read
 * one level up: a role you could not have built is a role you cannot hand out,
 * or assigning becomes the way around the grant check.
 */
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
                $beyondReach = Role::query()
                    ->whereIn('name', (array) $this->input('roles', []))
                    ->with('permissions:id,name')
                    ->get()
                    ->filter(fn (Role $role) => $role->permissions
                        ->contains(fn ($permission) => $this->user()->cannot($permission->name)))
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
