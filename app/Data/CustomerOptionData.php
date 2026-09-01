<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerType;
use App\Models\Customer;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# The first four fields must stay identical to `OptionData`, in order: the
# combobox takes this in its place.
#[TypeScript(location: ['App', 'Data'])]
class CustomerOptionData extends Data
{
    public function __construct(
        public int $value,
        public string $label,
        public ?string $hint,
        public ?string $image_url,
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
