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
     * @param  array<int, int>  $product_ids
     * @param  array<int, OptionData>  $products
     */
    public function __construct(
        public int $id,
        public int $customer_id,
        public CustomerType $customer_type,
        public ?string $customer_name,
        public ?string $company_name,
        public string $phone,
        public ?string $email,
        public string $visited_on,
        public string $visited_time,
        public VisitPurpose $purpose,
        public CustomerSource $source,
        public ?string $respondent,
        public ?string $expected_follow_up_on,
        public ?int $duration_minutes,
        public ?string $notes,
        public array $product_ids,
        /* The products already chosen, so the box can name one dropped from
           the catalogue since without falling back to a bare id. */
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
            company_name: $customer->company_name,
            phone: $customer->phone,
            email: $customer->email,
            visited_on: $visit->visited_at->format('Y-m-d'),
            visited_time: $visit->visited_at->format('H:i'),
            purpose: $visit->purpose,
            source: $visit->source,
            respondent: $visit->respondent,
            expected_follow_up_on: $visit->expected_follow_up_on?->format('Y-m-d'),
            duration_minutes: $visit->duration_minutes,
            notes: $visit->notes,
            product_ids: $visit->products->pluck('id')->all(),
            products: $visit->products
                ->map(OptionData::fromProduct(...))
                ->values()
                ->all(),
            customer_label: $customer->displayName(),
        );
    }
}
