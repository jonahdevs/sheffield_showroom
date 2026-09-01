<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerType;
use App\Models\Customer;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# The business half stays mounted for an individual so the toggle does not lose
# what was typed; `CustomerRequest` is what stops it being saved.
#[TypeScript(location: ['App', 'Data'])]
class CustomerFormData extends Data
{
    public function __construct(
        public int $id,
        public CustomerType $type,
        public ?string $name,
        public string $phone,
        public ?string $email,
        public ?string $id_number,
        public ?string $company_name,
        public ?string $industry,
        public string $country,
        public ?string $state,
        public ?string $city,
        public ?string $street_address,
        public ?string $area,
        public ?string $postal_code,
        public ?string $notes,
        public string $display_name,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        return new self(
            id: $customer->id,
            type: $customer->type,
            name: $customer->name,
            phone: $customer->phone,
            email: $customer->email,
            id_number: $customer->id_number,
            company_name: $customer->company_name,
            industry: $customer->industry,
            country: $customer->country,
            state: $customer->state,
            city: $customer->city,
            street_address: $customer->street_address,
            area: $customer->area,
            postal_code: $customer->postal_code,
            notes: $customer->notes,
            display_name: $customer->displayName(),
        );
    }
}
