<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\RewardResultStatus;
use App\Enums\RewardType;
use App\Models\ShuffleResult;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# Must say nothing about the pool - not which unit, not how many are left. The
# reveal is a public page and would otherwise leak the inventory.
#[TypeScript(location: ['App', 'Data'])]
class ShuffleRewardData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
        public RewardType $type,
        public string $type_label,
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

    # `$withCustomer` stays off by default: the reveal is reached with nothing
    # but a token, and a name on it turns a photographed QR code into somebody
    # else's personal information.
    public static function fromModel(ShuffleResult $result, bool $withCustomer = false): self
    {
        $reward = $result->poolEntry->reward->reward;
        $redemption = $result->relationLoaded('redemption') ? $result->redemption : null;

        return new self(
            code: $result->code,
            name: $reward->readableName(),
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
