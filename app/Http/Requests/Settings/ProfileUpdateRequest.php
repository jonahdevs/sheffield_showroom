<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `email` is omitted from the rules entirely without `profile.email.update` - not
 * `prohibited` - because the field is disabled on the form, so anything arriving
 * under that name is a stale page rather than an attack. Omitting it keeps it out
 * of `validated()`, so it can never reach the model.
 */
class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
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
