<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\RewardResultStatus;
use App\Enums\RewardType;
use App\Models\ShuffleResult;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A won reward, as both the customer's reveal and the redemption desk read it.
 *
 * Deliberately says nothing about the pool it came from - not which unit, not
 * how many are left, not what the odds were. The customer is being shown what
 * they won; the rest is the showroom's business, and a page that carried it
 * would be a page somebody could read the inventory out of.
 */
#[TypeScript(location: ['App', 'Data'])]
class ShuffleRewardData extends Data
{
    public function __construct(
        /** What the customer quotes when they come back for it. */
        public string $code,
        public string $name,
        public ?string $description,
        public RewardType $type,
        public string $type_label,
        /** "10%" or "KSh 5,000.00", or null when the reward carries no figure. */
        public ?string $value,
        public ?string $terms,
        public RewardResultStatus $status,
        public string $status_label,
        public string $won_on,
        public ?string $expires_on,
        public bool $is_redeemable,
        public ?string $customer_name = null,
        public ?string $redeemed_on = null,
        public ?string $redeemed_by = null,
    ) {}

    /**
     * The reward alone, for the customer's reveal.
     *
     * `$withCustomer` is off by default on purpose: the public page is reached
     * with nothing but a token, and a name on it would turn a photographed QR
     * code into somebody else's personal information.
     */
    public static function fromModel(ShuffleResult $result, bool $withCustomer = false): self
    {
        $reward = $result->poolEntry->reward;
        $redemption = $result->relationLoaded('redemption') ? $result->redemption : null;

        return new self(
            code: $result->code,
            name: $reward->name,
            description: $reward->description,
            type: $reward->type,
            type_label: $reward->type->label(),
            value: $reward->readableValue(),
            terms: $reward->terms,
            status: $result->status,
            status_label: $result->status->label(),
            won_on: $result->won_at->toFormattedDayDateString(),
            expires_on: $result->expires_at?->toFormattedDayDateString(),
            is_redeemable: $result->isRedeemable(),
            customer_name: $withCustomer
                ? $result->session?->customer?->displayName()
                : null,
            redeemed_on: $redemption?->redeemed_at->toFormattedDayDateString(),
            redeemed_by: $redemption?->redeemer?->name,
        );
    }
}
