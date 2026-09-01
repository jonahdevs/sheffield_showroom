<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerType;
use App\Enums\RewardResultStatus;
use App\Enums\RewardType;
use App\Models\ShuffleResult;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# Never merge with `ShuffleRewardData`. The reveal is reached with nothing but
# a token, and one object serving both puts the customer's name one forgotten
# flag away from a page anybody who photographed a QR code can open.
#[TypeScript(location: ['App', 'Data'])]
class RewardWinnerRowData extends Data
{
    /**
     * Eager-load these or the page costs a query per relation per row.
     *
     * @var array<int, string>
     */
    public const RELATIONS = [
        'session.customer',
        'session.campaign:id,name',
        'poolEntry.reward.reward.product:id,name',
        'poolEntry.reward.qualifyingProducts:id,name',
        'session.purchase.products:id,name',
        'redemption.redeemer:id,name',
    ];

    public function __construct(
        public int $id,
        public string $code,
        public string $customer_name,
        public CustomerType $customer_type,
        public ?string $customer_company,
        public ?string $customer_phone,
        public string $campaign_name,
        public string $reward_name,
        public RewardType $type,
        public string $type_label,
        public ?string $value,
        public string $won_on,
        public ?string $expires_on,
        public RewardResultStatus $status,
        public string $status_label,
        public ?string $redeemed_on,
        public ?string $redeemed_by,
        public ?string $purchase_reference,
        public ?string $purchased_on,
        /**
         * The intersection of the receipt and the reward's pairing, not the
         * whole receipt. Empty is the common case: the reward named no
         * products, so any purchase qualified.
         *
         * @var array<int, string>
         */
        public array $qualifying_products,
    ) {}

    public static function fromModel(ShuffleResult $result): self
    {
        $customer = $result->session?->customer;
        $attachment = $result->poolEntry->reward;
        $reward = $attachment->reward;
        $redemption = $result->redemption;
        $purchase = $result->session?->purchase;

        $pairedTo = $attachment->qualifyingProducts->pluck('id');

        return new self(
            id: $result->id,
            code: $result->code,
            customer_name: $customer?->name
                ?? $customer?->displayName()
                ?? 'Unknown customer',
            customer_type: $customer?->type ?? CustomerType::Individual,
            customer_company: $customer?->company_name,
            customer_phone: $customer?->phone,
            campaign_name: $result->session?->campaign?->name ?? 'Unknown campaign',
            reward_name: $reward->readableName(),
            type: $reward->type,
            type_label: $reward->type->label(),
            value: $reward->readableValue(),
            won_on: $result->won_at->format('j M Y'),
            expires_on: $result->expires_at?->format('j M Y'),
            status: $result->status,
            status_label: $result->status->label(),
            redeemed_on: $redemption?->redeemed_at->format('j M Y'),
            redeemed_by: $redemption?->redeemer?->name,
            purchase_reference: $purchase?->reference,
            purchased_on: $purchase?->purchased_at->format('j M Y'),
            qualifying_products: $pairedTo->isEmpty() || $purchase === null
                ? []
                : $purchase->products
                    ->whereIn('id', $pairedTo->all())
                    ->pluck('name')
                    ->values()
                    ->all(),
        );
    }
}
