<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Rewards\RewardEligibilityService;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# `can_delete` is per-row, not a page-wide flag: a sale that has already earned
# a turn cannot be removed whatever the viewer holds. See `PurchasePolicy`.
#[TypeScript(location: ['App', 'Data'])]
class PurchaseRowData extends Data
{
    public function __construct(
        public int $id,
        public string $customer_name,
        public ?string $reference,
        /** @var array<int, string> */
        public array $product_names,
        public string $amount,
        public PurchaseStatus $status,
        public string $status_label,
        public string $purchased_on,
        public ?int $shuffle_id,
        public ?string $shuffle_status,
        public ?string $refusal,
        public bool $can_delete,
    ) {}

    public static function fromModel(
        Purchase $purchase,
        User $viewer,
        RewardEligibilityService $eligibility,
    ): self {
        $session = $purchase->shuffleSession;

        return new self(
            id: $purchase->id,
            customer_name: $purchase->customer->displayName(),
            reference: $purchase->reference,
            product_names: $purchase->products->pluck('name')->all(),
            amount: number_format((float) $purchase->amount, 2),
            status: $purchase->status,
            status_label: $purchase->status->label(),
            purchased_on: $purchase->purchased_at->toFormattedDayDateString(),
            shuffle_id: $session?->id,
            shuffle_status: $session?->status->label(),
            # Only asked when there is no turn yet; the question costs a query.
            refusal: $session !== null ? null : $eligibility->refusalFor($purchase),
            can_delete: $viewer->can('delete', $purchase),
        );
    }
}
