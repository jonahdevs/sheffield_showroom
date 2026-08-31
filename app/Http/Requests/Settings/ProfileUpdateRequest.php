<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What somebody may change about their own account.
 *
 * The name, always. The email address only if they hold
 * `profile.email.update`, which no role grants by default: it is the address a
 * sign-in is made at and the one a reset would be sent to, so an account that
 * can move it can be handed to whoever is sitting at an unlocked laptop.
 * Normally that change is an administrator's job on the Users screen, behind
 * `users.update` - see `App\Http\Requests\Admin\UserRequest`.
 *
 * Left out of the rules rather than `prohibited` when the permission is
 * missing: the field is disabled on the form, so anything arriving under that
 * name is a stale page rather than somebody to be told off. It is dropped on
 * the way in and never reaches the model.
 */
class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['name' => $this->nameRules()];

        if ($this->user()->can(Permission::ProfileEmailUpdate->value)) {
            $rules['email'] = $this->emailRules($this->user()->id);
        }

        return $rules;
    }
}
