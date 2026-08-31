<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerType;
use App\Enums\VisitPurpose;
use App\Models\Visit;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A visit as the list shows it. The write-up itself - details and notes -
 * stays on the record; a table row carries who, why and when.
 *
 * `attended_by` falls back to whoever logged it, so a row recorded before the
 * respondent was asked for still names somebody rather than a dash.
 */
#[TypeScript(location: ['App', 'Data'])]
class VisitRowData extends Data
{
    /**
     * What `fromModel` reaches for, so whoever builds a list of visits can
     * eager-load it.
     *
     * Named here rather than at each call site because a caller that forgets
     * one of them gets a working page and three extra queries per row - the
     * kind of fault that only shows up once the log is a few thousand visits
     * long. `products` by name only: the row says what they were shown, not
     * the catalogue behind it.
     *
     * @var array<int, string>
     */
    public const RELATIONS = ['customer', 'creator', 'products:id,name'];

    /**
     * @param  array<int, string>  $products
     */
    public function __construct(
        public int $id,
        /* The person, as the customers list names them: a company customer is
           somebody who came in for a business, and the business is the line
           under them rather than the line itself. */
        public string $customer_name,
        public CustomerType $customer_type,
        public ?string $customer_company,
        public ?string $customer_phone,
        public VisitPurpose $purpose,
        public string $purpose_label,
        public string $visited_on,
        public string $visited_time,
        /**
         * What they were shown, by name.
         *
         * @var array<int, string>
         */
        public array $products,
        public ?string $attended_by,
        /** Whether anything was written up beyond the required fields. */
        public bool $has_notes,
    ) {}

    public static function fromModel(Visit $visit): self
    {
        return new self(
            id: $visit->id,
            customer_name: $visit->customer?->name
                ?? $visit->customer?->displayName()
                ?? 'Unknown customer',
            customer_type: $visit->customer?->type ?? CustomerType::Individual,
            customer_company: $visit->customer?->company_name,
            customer_phone: $visit->customer?->phone,
            purpose: $visit->purpose,
            purpose_label: $visit->purpose->label(),
            visited_on: $visit->visited_at->format('j M Y'),
            visited_time: $visit->visited_at->format('H:i'),
            products: $visit->products->pluck('name')->all(),
            attended_by: $visit->respondent ?? $visit->creator?->name,
            has_notes: filled($visit->notes),
        );
    }
}
