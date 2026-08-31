<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\UserFormData;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Pinning capabilities to one account rather than to the job it does.
 *
 * Two rules keep the direct set honest. The first is the ceiling every grant
 * in this application sits under: nobody hands out what they do not hold
 * themselves, or this becomes the shortest route past `RoleRequest`.
 *
 * The second is narrower and is the reason the whole feature is risky. A
 * permission a role already carries may not also be pinned to the account,
 * because a duplicate grant survives the role being taken away and there is
 * nothing on the Roles screen to say so. Refusing it here means the direct set
 * only ever holds what the roles do not, so revoking a role really does revoke.
 */
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

                /* Only what this write is adding. A duplicate that already
                   exists - left behind by a role gaining a permission its
                   holder was separately given - has to stay saveable, or the
                   one form that can clear it would refuse to submit. */
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
        return array_values(array_unique((array) $this->input('permissions', [])));
    }

    public function subject(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }
}
