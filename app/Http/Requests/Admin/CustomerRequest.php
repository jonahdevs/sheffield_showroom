<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CustomerType;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->customer();

        return $customer === null
            ? $this->user()->can('create', Customer::class)
            : $this->user()->can('update', $customer);
    }

    # Clearing, not omitting: switching the toggle mid-entry would otherwise
    # leave an employer attached to somebody buying in their own name.
    protected function prepareForValidation(): void
    {
        if ($this->input('type') === CustomerType::Individual->value) {
            $this->merge([
                'company_name' => null,
                'segment' => null,
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

            # -----------------------------------------------------------------
            # Basic
            # -----------------------------------------------------------------
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\s-]+$/'],
            'email' => ['nullable', 'email', 'max:180'],
            # Deliberately not unique: it is often taken down after the rest of
            # the record, and a half-entered number must not lock the next one out.
            'id_number' => ['nullable', 'string', 'max:30'],

            # -----------------------------------------------------------------
            # Business
            # -----------------------------------------------------------------
            'company_name' => [Rule::requiredIf($isCompany), 'nullable', 'string', 'max:160'],
            'segment' => ['nullable', 'string', 'max:120'],

            # -----------------------------------------------------------------
            # Address
            # -----------------------------------------------------------------
            'country' => ['required', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'city' => ['nullable', 'string', 'max:90'],
            'street_address' => ['nullable', 'string', 'max:180'],
            'area' => ['nullable', 'string', 'max:180'],
            'postal_code' => ['nullable', 'string', 'max:20'],

            # -----------------------------------------------------------------
            # Additional
            # -----------------------------------------------------------------
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
