<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerType;
use App\Models\Customer;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A customer as the list shows them. Deliberately thinner than
 * `CustomerFormData`: the table has no column for a date of birth or a note.
 */
#[TypeScript(location: ['App', 'Data'])]
class CustomerRowData extends Data
{
    public function __construct(
        public int $id,
        public CustomerType $type,
        public string $type_label,
        public string $display_name,
        public ?string $subtitle,
        public string $phone,
        public ?string $email,
        public string $location,
        public string $added,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        return new self(
            id: $customer->id,
            type: $customer->type,
            type_label: $customer->type->label(),
            display_name: $customer->displayName(),
            subtitle: $customer->subtitle(),
            phone: $customer->phone,
            email: $customer->email,
            /* Town and country. The rest of the address belongs on the
               record rather than in a table cell, and the country carries a
               default so there is always something to show. */
            location: implode(', ', array_filter([
                $customer->city ?? $customer->state,
                $customer->country,
            ])),
            added: $customer->created_at?->format('j M Y') ?? '',
        );
    }
}
