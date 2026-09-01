<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\UserFormData;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

# Nobody grants what they do not hold themselves, and the direct set must stay
# disjoint from what the roles grant: a duplicate survives the role being taken
# away and shows up nowhere, so revoking a role would stop revoking.
class UserPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->subject();

        return $subject !== null && $this->user()->can('managePermissions', $subject);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $wanted = $this->permissions();

                $ungrantable = array_values(array_diff($wanted, $this->grantable()));

                if ($ungrantable !== []) {
                    $validator->errors()->add(
                        'permissions',
                        'You cannot grant a permission you do not hold yourself: '
                            .implode(', ', $ungrantable).'.',
                    );
                }

                $subject = $this->subject();

                if ($subject === null) {
                    return;
                }

                # Only what this write adds. An existing duplicate must stay
                # saveable or the one form that can clear it cannot submit.
                $added = array_diff($wanted, $subject->permissions->pluck('name')->all());

                $inherited = array_values(array_intersect(
                    $added,
                    array_keys(UserFormData::inherited($subject)),
                ));

                if ($inherited !== []) {
                    $validator->errors()->add(
                        'permissions',
                        'A role already grants this, so pinning it to the account would outlive the role: '
                            .implode(', ', $inherited).'.',
                    );
                }
            },
        ];
    }

    /**
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
        return array_values(array_unique((array) $this->input('permissions', [])));
    }

    public function subject(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }
}
