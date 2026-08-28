<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerType;
use App\Models\Customer;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A customer as the visit form's picker needs them.
 *
 * `OptionData` plus the details the form fills in behind the pick. A label
 * alone cannot say which of the two name columns it came from, so a company
 * chosen off the list would be shown back as an individual called by its
 * company name - right enough to save, wrong enough that nobody could use it
 * to check they had the right customer.
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
        public CustomerType $type,
        public ?string $name,
        public ?string $company_name,
        public ?string $email,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        return new self(
            value: $customer->id,
            label: $customer->displayName(),
            hint: $customer->phone,
            image_url: null,
            type: $customer->type,
            name: $customer->name,
            company_name: $customer->company_name,
            email: $customer->email,
        );
    }
}
