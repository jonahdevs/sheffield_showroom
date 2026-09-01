<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerType;
use App\Models\Visit;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# `visited_on` and `visited_time` are joined back into one `visited_at` by
# `VisitRequest`.
#[TypeScript(location: ['App', 'Data'])]
class VisitFormData extends Data
{
    /**
     * @param  array<int, ProductOptionData>  $products
     */
    public function __construct(
        public int $id,
        public int $customer_id,
        public CustomerType $customer_type,
        public ?string $customer_name,
        public string $phone,
        public ?string $email,
        public ?string $id_number,
        public ?string $company_name,
        public ?string $segment,
        public string $visited_on,
        public string $visited_time,
        public string $purpose,
        public string $source,
        public ?string $referred_by,
        public ?string $department,
        public ?string $respondent,
        public ?string $expected_follow_up_on,
        public ?string $notes,
        # The whole row rather than an id, so a product dropped from the
        # catalogue since still has a name to show.
        public array $products,
        public string $customer_label,
    ) {}

    public static function fromModel(Visit $visit): self
    {
        $customer = $visit->customer;

        return new self(
            id: $visit->id,
            customer_id: $visit->customer_id,
            customer_type: $customer->type,
            customer_name: $customer->name,
            phone: $customer->phone,
            email: $customer->email,
            id_number: $customer->id_number,
            company_name: $customer->company_name,
            segment: $customer->segment,
            visited_on: $visit->visited_at->format('Y-m-d'),
            visited_time: $visit->visited_at->format('H:i'),
            purpose: $visit->purpose,
            source: $visit->source,
            referred_by: $visit->referred_by,
            department: $visit->department,
            respondent: $visit->respondent,
            expected_follow_up_on: $visit->expected_follow_up_on?->format('Y-m-d'),
            notes: $visit->notes,
            products: $visit->products
                ->map(ProductOptionData::fromVisitProduct(...))
                ->values()
                ->all(),
            customer_label: $customer->displayName(),
        );
    }
}
