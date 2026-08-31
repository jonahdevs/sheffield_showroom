<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\VisitPurpose;
use App\Models\Visit;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Every field the visit form reads and writes, the customer's half included.
 *
 * The form finds a customer or types one, so on an edit it needs the details
 * of whoever is already attached - it shows them back rather than making
 * somebody look the record up to check they have the right person.
 *
 * The moment is split into a date and a time because that is how it is
 * entered - a calendar and a clock are two controls - and joined back into one
 * `visited_at` by `VisitRequest`.
 */
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
        public ?string $industry,
        public string $visited_on,
        public string $visited_time,
        public VisitPurpose $purpose,
        public CustomerSource $source,
        public ?string $respondent,
        public ?string $expected_follow_up_on,
        public ?string $notes,
        /* What they were shown and how keen they were on each. Carries the
           whole row rather than an id, so a product dropped from the catalogue
           since still has a name to show rather than a bare number. */
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
            industry: $customer->industry,
            visited_on: $visit->visited_at->format('Y-m-d'),
            visited_time: $visit->visited_at->format('H:i'),
            purpose: $visit->purpose,
            source: $visit->source,
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
