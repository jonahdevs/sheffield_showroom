<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CustomerType;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating and editing a customer.
 *
 * Both types live in one table, so this is the only thing standing between a
 * company row and a stray date of birth: the fields belonging to the other
 * type are required-or-forbidden by `type`, and `prepareForValidation` clears
 * whatever the form left behind when somebody switched the toggle mid-entry.
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->customer();

        return $customer === null
            ? $this->user()->can('create', Customer::class)
            : $this->user()->can('update', $customer);
    }

    /**
     * A customer is one type or the other, and the form only ever shows one
     * set of fields. Anything left over from the other set is dropped here
     * rather than validated, so switching the toggle cannot carry a value
     * across.
     */
    protected function prepareForValidation(): void
    {
        $type = $this->input('type');

        if ($type === CustomerType::Individual->value) {
            $this->merge([
                'company_name' => null,
                'industry' => null,
                'contact_person' => null,
                'contact_person_position' => null,
            ]);
        }

        if ($type === CustomerType::Company->value) {
            $this->merge([
                'name' => null,
                'date_of_birth' => null,
                'occupation' => null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isIndividual = $this->input('type') === CustomerType::Individual->value;

        return [
            'type' => ['required', Rule::enum(CustomerType::class)],

            // --- Individual ---------------------------------------------
            'name' => [Rule::requiredIf($isIndividual), 'nullable', 'string', 'max:120'],
            /* Nobody is buying steel before they are born, and a date this
               side of a century ago is a typo rather than a customer. */
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'occupation' => ['nullable', 'string', 'max:120'],

            // --- Company --------------------------------------------------
            'company_name' => [Rule::requiredIf(! $isIndividual), 'nullable', 'string', 'max:160'],
            'industry' => ['nullable', 'string', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'contact_person_position' => ['nullable', 'string', 'max:120'],

            // --- Shared ---------------------------------------------------
            /* Digits, spaces and the punctuation people actually write:
               +254 700 123 456, 0700-123-456, (020) 271 1000. */
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\s-]+$/'],
            'alternative_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s-]+$/', 'different:phone'],
            'email' => ['nullable', 'email', 'max:180'],

            'address_line_1' => ['nullable', 'string', 'max:180'],
            'address_line_2' => ['nullable', 'string', 'max:180'],
            'city' => ['nullable', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:90'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_name' => 'company name',
            'contact_person_position' => 'position',
            'alternative_phone' => 'alternative phone number',
            'address_line_1' => 'address line 1',
            'address_line_2' => 'address line 2',
            'state' => 'state or province',
            'postal_code' => 'postal code',
            'phone' => 'phone number',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Use digits, spaces, brackets, + and - only.',
            'alternative_phone.regex' => 'Use digits, spaces, brackets, + and - only.',
            'alternative_phone.different' => 'The alternative number is the same as the main one.',
        ];
    }

    public function customer(): ?Customer
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer ? $customer : null;
    }
}
