<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerType;
use App\Models\Customer;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A customer as the visit form's name box needs them.
 *
 * `OptionData` plus the details the form fills in behind the pick. The box
 * asks for a person's name, so that is the label - a company customer is a
 * person who came in for a business, and the business is what the label alone
 * cannot say. `keywords` carries it anyway so a salesperson who remembers the
 * company rather than the face still finds them.
 *
 * The first four fields match `OptionData` exactly so the combobox can take
 * this in its place.
 */
#[TypeScript(location: ['App', 'Data'])]
class CustomerOptionData extends Data
{
    public function __construct(
        public int $value,
        public string $label,
        public ?string $hint,
        /** Always null. Customers have no picture; the combobox reads it. */
        public ?string $image_url,
        /** Searched but not shown: the company behind a company customer. */
        public ?string $keywords,
        public CustomerType $type,
        public ?string $name,
        public ?string $company_name,
        public ?string $industry,
        public ?string $email,
        public ?string $id_number,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        return new self(
            value: $customer->id,
            /* Their own name. `displayName()` only where it is missing, which
               is a company recorded before the name was asked of both. */
            label: $customer->name ?? $customer->displayName(),
            hint: $customer->phone,
            image_url: null,
            keywords: $customer->company_name,
            type: $customer->type,
            name: $customer->name,
            company_name: $customer->company_name,
            industry: $customer->industry,
            email: $customer->email,
            id_number: $customer->id_number,
        );
    }
}
