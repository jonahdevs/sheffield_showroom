<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\RoleAssignmentRules;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating and editing a role. The rule that matters is the last one: nobody
 * may grant a permission they do not hold themselves, or a role becomes a way
 * to promote yourself past the ceiling your own account sits under. It lives
 * in `RoleAssignmentRules`, shared with the screens that hand roles and
 * permissions to a person.
 */
class RoleRequest extends FormRequest
{
    use RoleAssignmentRules;

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
            ...$this->permissionRules(),
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
        return [fn (Validator $validator) => $this->refusePermissionsBeyondReach($validator)];
    }

    public function role(): ?Role
    {
        $role = $this->route('role');

        return $role instanceof Role ? $role : null;
    }
}
