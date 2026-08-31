<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Rewards\RewardEligibilityService;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One sale as the purchases list reads it.
 *
 * Carries the reward question with it. That is the only reason this table
 * exists, so the row says whether the sale has been given a turn, or - when it
 * has not - the sentence explaining why, in words somebody at a counter can
 * repeat to the customer in front of them.
 *
 * `can_delete` is per-row rather than a blanket flag on the page, because the
 * answer depends on the row: a sale that has already earned somebody a turn
 * cannot be removed at all, whatever the viewer holds. See `PurchasePolicy`.
 */
#[TypeScript(location: ['App', 'Data'])]
class PurchaseRowData extends Data
{
    public function __construct(
        public int $id,
        public string $customer_name,
        public ?string $reference,
        /** Formatted for reading, not for arithmetic. */
        public string $amount,
        public PurchaseStatus $status,
        public string $status_label,
        public string $purchased_on,
        public ?int $shuffle_id,
        public ?string $shuffle_status,
        /** Why this sale has not earned a turn, or null when it has or could. */
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
            amount: number_format((float) $purchase->amount, 2),
            status: $purchase->status,
            status_label: $purchase->status->label(),
            purchased_on: $purchase->purchased_at->toFormattedDayDateString(),
            shuffle_id: $session?->id,
            shuffle_status: $session?->status->label(),
            /* Only asked when there is no turn yet. A sale that already has
               one needs no explanation, and the question costs a query. */
            refusal: $session !== null ? null : $eligibility->refusalFor($purchase),
            can_delete: $viewer->can('delete', $purchase),
        );
    }
}
