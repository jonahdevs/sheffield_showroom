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
        public ?int $customer_id,
        public string $visitor_type,
        public ?CustomerType $customer_type,
        public ?string $visitor_name,
        public ?string $phone,
        public ?string $email,
        public ?string $id_number,
        public ?string $organisation,
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
        public string $visitor_label,
    ) {}

    public static function fromModel(Visit $visit): self
    {
        # Null on every visit by somebody who was not buying: their details are on
        # the visit rather than in the customer book.
        $customer = $visit->customer;

        return new self(
            id: $visit->id,
            customer_id: $visit->customer_id,
            visitor_type: $visit->visitor_type,
            customer_type: $customer?->type,
            visitor_name: $customer?->name ?? $visit->visitor_name,
            phone: $customer?->phone ?? $visit->visitor_phone,
            email: $customer?->email,
            id_number: $customer?->id_number,
            organisation: $customer?->company_name ?? $visit->visitor_organisation,
            segment: $customer?->segment,
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
            visitor_label: $visit->visitorName(),
        );
    }
}
