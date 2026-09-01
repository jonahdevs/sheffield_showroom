<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\VisitDepartment;
use App\Enums\VisitPurpose;
use App\Models\Visit;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class VisitRowData extends Data
{
    /**
     * Eager-load these or the page costs three extra queries per row.
     *
     * @var array<int, string>
     */
    public const RELATIONS = ['customer', 'creator', 'products:id,name'];

    /**
     * @param  array<int, string>  $products
     */
    public function __construct(
        public int $id,
        public string $customer_name,
        public CustomerType $customer_type,
        public ?string $customer_company,
        public ?string $customer_phone,
        public string $purpose,
        public string $purpose_label,
        public string $source,
        public string $source_label,
        public ?string $department,
        public ?string $department_label,
        public string $visited_on,
        public string $visited_time,
        /** @var array<int, string> */
        public array $products,
        public ?string $attended_by,
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
            purpose_label: VisitPurpose::readable($visit->purpose),
            source: $visit->source,
            source_label: CustomerSource::readable($visit->source),
            department: $visit->department,
            department_label: $visit->department === null
                ? null
                : VisitDepartment::readable($visit->department),
            visited_on: $visit->visited_at->format('j M Y'),
            visited_time: $visit->visited_at->format('H:i'),
            products: $visit->products->pluck('name')->all(),
            attended_by: $visit->respondent ?? $visit->creator?->name,
            has_notes: filled($visit->notes),
        );
    }
}
