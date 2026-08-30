<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Concerns\RoleAssignmentRules;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Creating and editing an account.
 *
 * The same fields the person themselves would fill in, plus the three they may
 * not: the email they sign in with, the roles they hold, and anything granted
 * to them alone. All three are the administrator's to set, which is the whole
 * reason this screen exists.
 */
class UserRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules, RoleAssignmentRules;

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

            /* A new account needs a password to be usable. An existing one
               already has one, so the field is a replacement rather than a
               requirement, and an empty box leaves what is on file alone. */
            'password' => $subject === null
                ? $this->passwordRules()
                : ['nullable', 'string', Password::default(), 'confirmed'],
        ];

        if ($this->mayGrant()) {
            $rules += $this->roleRules(optional: true);
            $rules += $this->permissionRules(optional: true);
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        if (! $this->mayGrant()) {
            return [];
        }

        return [
            fn (Validator $validator) => $this->refuseRolesBeyondReach($validator),
            fn (Validator $validator) => $this->refusePermissionsBeyondReach($validator),
        ];
    }

    /**
     * Whether the roles and permissions on this request are the sender's to
     * set.
     *
     * False drops them entirely rather than failing: somebody who may edit a
     * name and an email without holding `roles.assign` should be able to save
     * this form, not be told off by fields they were never shown.
     */
    public function mayGrant(): bool
    {
        $subject = $this->subject();

        return $subject === null
            ? $this->user()->can(Permission::RolesAssign->value)
            : $this->user()->can('assignTo', [Role::class, $subject]);
    }

    /**
     * The account being edited, or null when one is being created.
     */
    public function subject(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }
}
