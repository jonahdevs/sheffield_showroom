<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UserPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        $subject = $this->subject();

        return $subject !== null && $this->user()->can('updatePassword', $subject);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['password' => $this->passwordRules()];
    }

    public function subject(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }
}
