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
 * Somebody walks in, gives their name, and says whether they are buying for
 * themselves or for the business they work for. So the name, the number and
 * the address are asked of everybody, and only the company's own two fields
 * turn on the type. `prepareForValidation` clears those two when the answer is
 * an individual, so switching the toggle mid-entry cannot leave an employer
 * attached to somebody buying in their own name.
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
     * The business section is only on screen for a company. Anything left in
     * it by a switched toggle is dropped here rather than validated.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('type') === CustomerType::Individual->value) {
            $this->merge([
                'company_name' => null,
                'industry' => null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isCompany = $this->input('type') === CustomerType::Company->value;

        return [
            'type' => ['required', Rule::enum(CustomerType::class)],

            // --- Basic ----------------------------------------------------
            /* Asked of both types. A company does not walk into a showroom;
               somebody from it does, and they are who the counter deals
               with. */
            'name' => ['required', 'string', 'max:120'],
            /* Digits, spaces and the punctuation people actually write:
               +254 700 123 456, 0700-123-456, (020) 271 1000. */
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\s-]+$/'],
            'email' => ['nullable', 'email', 'max:180'],
            /* A National ID as it is printed. Not unique: it is often taken
               down later than the rest of the record, and a half-entered
               number must not lock the next person out of entering theirs. */
            'id_number' => ['nullable', 'string', 'max:30'],

            // --- Business -------------------------------------------------
            'company_name' => [Rule::requiredIf($isCompany), 'nullable', 'string', 'max:160'],
            'industry' => ['nullable', 'string', 'max:120'],

            // --- Address --------------------------------------------------
            'country' => ['required', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'city' => ['nullable', 'string', 'max:90'],
            'street_address' => ['nullable', 'string', 'max:180'],
            'area' => ['nullable', 'string', 'max:180'],
            'postal_code' => ['nullable', 'string', 'max:20'],

            // --- Additional -----------------------------------------------
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'full name',
            'phone' => 'phone number',
            'id_number' => 'ID number',
            'company_name' => 'company name',
            'state' => 'state or province',
            'street_address' => 'street address',
            'area' => 'area or estate',
            'postal_code' => 'postal code',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Record who you spoke to, company or not.',
            'phone.regex' => 'Use digits, spaces, brackets, + and - only.',
            'company_name.required' => 'A company customer needs the name of the company.',
        ];
    }

    public function customer(): ?Customer
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer ? $customer : null;
    }
}
