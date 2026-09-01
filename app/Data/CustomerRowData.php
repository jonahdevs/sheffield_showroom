<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerType;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class CustomerRowData extends Data
{
    public function __construct(
        public int $id,
        public CustomerType $type,
        public string $type_label,
        public string $display_name,
        public ?string $name,
        public ?string $company_name,
        public string $phone,
        public ?string $email,
        public int $visits_count,
        public ?string $last_visit,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        return new self(
            id: $customer->id,
            type: $customer->type,
            type_label: $customer->type->label(),
            display_name: $customer->displayName(),
            name: $customer->name,
            company_name: $customer->company_name,
            phone: $customer->phone,
            email: $customer->email,
            visits_count: (int) ($customer->visits_count ?? 0),
            last_visit: self::lastVisit($customer),
        );
    }

    # `withMax` returns whatever the driver gave it - a string on some, a cast
    # value on others - so parse rather than formatting it directly.
    private static function lastVisit(Customer $customer): ?string
    {
        $moment = $customer->visits_max_visited_at ?? null;

        return $moment === null
            ? null
            : CarbonImmutable::parse($moment)->format('j M Y');
    }
}
