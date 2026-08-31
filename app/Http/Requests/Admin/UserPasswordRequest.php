<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Setting somebody else's password.
 *
 * The showroom has no outbound mail worth relying on, so an administrator hands
 * the new password over in person rather than posting a link and hoping it
 * arrives. That makes this the most dangerous thing on the Users screen, so it
 * answers to `users.update` on an account the actor's own reach already covers
 * — see `UserPolicy::updatePassword`.
 *
 * `Password::defaults()` rather than a rule spelled out here: the strength the
 * showroom asks for is decided once, in `AppServiceProvider`, and an
 * administrator setting a password for somebody should not be held to a
 * different bar than that person setting their own.
 */
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
