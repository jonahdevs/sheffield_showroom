<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\RoleAssignmentRules;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Setting which roles a user holds, from the Roles screen's Users panel. The
 * ceiling it enforces lives in `RoleAssignmentRules`, shared with the account
 * form, which sets the same thing from the other direction.
 */
class UserRolesRequest extends FormRequest
{
    use RoleAssignmentRules;

    public function authorize(): bool
    {
        return $this->user()->can('assignTo', [Role::class, $this->subject()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->roleRules();
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->refuseRolesBeyondReach($validator)];
    }

    public function subject(): User
    {
        $user = $this->route('user');

        abort_unless($user instanceof User, 404);

        return $user;
    }
}
