<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating and editing a role. The rule that matters is the last one: nobody
 * may grant a permission they do not hold themselves, or a role becomes a way
 * to promote yourself past the ceiling your own account sits under.
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->role();

        return $role === null
            ? $this->user()->can('create', Role::class)
            : $this->user()->can('update', $role);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $role = $this->role();

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:60',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('roles', 'name')->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Use lower case letters, numbers and hyphens only.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $ungrantable = array_values(array_diff(
                    (array) $this->input('permissions', []),
                    $this->grantable(),
                ));

                if ($ungrantable !== []) {
                    $validator->errors()->add(
                        'permissions',
                        'You cannot grant a permission you do not hold yourself: '
                            .implode(', ', $ungrantable).'.',
                    );
                }
            },
        ];
    }

    /**
     * What this person may hand out. A super admin passes every check through
     * `Gate::before`, so the whole set is theirs to give.
     *
     * @return array<int, string>
     */
    public function grantable(): array
    {
        return array_values(array_filter(
            Permission::values(),
            fn (string $permission) => $this->user()->can($permission),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return array_values(array_unique((array) $this->validated('permissions')));
    }

    public function role(): ?Role
    {
        $role = $this->route('role');

        return $role instanceof Role ? $role : null;
    }
}
