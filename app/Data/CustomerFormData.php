<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerType;
use App\Models\Customer;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Every field the form reads and writes, including the ones the other type
 * leaves null - the form keeps both halves mounted so switching the toggle
 * does not lose what was already typed.
 */
#[TypeScript(location: ['App', 'Data'])]
class CustomerFormData extends Data
{
    public function __construct(
        public int $id,
        public CustomerType $type,
        public ?string $name,
        public ?string $date_of_birth,
        public ?string $occupation,
        public ?string $company_name,
        public ?string $industry,
        public ?string $contact_person,
        public ?string $contact_person_position,
        public string $phone,
        public ?string $alternative_phone,
        public ?string $email,
        public ?string $address_line_1,
        public ?string $address_line_2,
        public ?string $city,
        public ?string $state,
        public ?string $postal_code,
        public string $country,
        public ?string $notes,
        public string $display_name,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        return new self(
            id: $customer->id,
            type: $customer->type,
            name: $customer->name,
            /* ISO for the date input, which accepts nothing else. */
            date_of_birth: $customer->date_of_birth?->format('Y-m-d'),
            occupation: $customer->occupation,
            company_name: $customer->company_name,
            industry: $customer->industry,
            contact_person: $customer->contact_person,
            contact_person_position: $customer->contact_person_position,
            phone: $customer->phone,
            alternative_phone: $customer->alternative_phone,
            email: $customer->email,
            address_line_1: $customer->address_line_1,
            address_line_2: $customer->address_line_2,
            city: $customer->city,
            state: $customer->state,
            postal_code: $customer->postal_code,
            country: $customer->country,
            notes: $customer->notes,
            display_name: $customer->displayName(),
        );
    }
}
