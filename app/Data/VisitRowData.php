<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerSource;
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
    public function __construct(
        public int $id,
        public string $customer_name,
        public ?string $customer_phone,
        public VisitPurpose $purpose,
        public string $purpose_label,
        public CustomerSource $source,
        public string $source_label,
        public string $visited_on,
        public string $visited_time,
        public ?string $duration,
        public int $product_count,
        public ?string $attended_by,
        /** Whether anything was written up beyond the required fields. */
        public bool $has_notes,
    ) {}

    public static function fromModel(Visit $visit): self
    {
        return new self(
            id: $visit->id,
            customer_name: $visit->customer?->displayName() ?? 'Unknown customer',
            customer_phone: $visit->customer?->phone,
            purpose: $visit->purpose,
            purpose_label: $visit->purpose->label(),
            source: $visit->source,
            source_label: $visit->source->label(),
            visited_on: $visit->visited_at->format('j M Y'),
            visited_time: $visit->visited_at->format('H:i'),
            duration: $visit->durationLabel(),
            /* Counted by `withCount` on the list query rather than by loading
               the rows, so a table of fifty visits is not fifty extra reads. */
            product_count: (int) ($visit->products_count ?? 0),
            attended_by: $visit->respondent ?? $visit->creator?->name,
            has_notes: filled($visit->notes),
        );
    }
}
