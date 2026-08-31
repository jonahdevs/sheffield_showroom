<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CustomerType;
use App\Enums\RewardResultStatus;
use App\Enums\RewardType;
use App\Models\ShuffleResult;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One won reward, as the list of them shows it.
 *
 * Distinct from `ShuffleRewardData`, which answers "what is this code" for a
 * customer holding a phone and for the redemption desk. This answers "who won
 * what, and has it been collected" for somebody reading down a page, so it
 * carries the customer and the campaign - two things the reveal screen must
 * never show - and drops the description and the terms, which are a paragraph
 * each and unreadable in a table cell.
 *
 * The pair are not merged. The reveal is reached with nothing but a token, and
 * a single object serving both would put a customer's name one forgotten flag
 * away from a page anybody who photographed a QR code can open.
 */
#[TypeScript(location: ['App', 'Data'])]
class RewardWinnerRowData extends Data
{
    /**
     * What `fromModel` reaches for, so whoever builds a list can eager-load it.
     *
     * Four relations deep in two directions, and every one of them is read for
     * every row: without this the page costs five queries per reward, which on
     * a fifty-row page is two hundred and fifty round trips to draw one table.
     *
     * @var array<int, string>
     */
    public const RELATIONS = [
        'session.customer',
        'session.campaign:id,name',
        /* Two hops rather than one since rewards moved into a catalogue: the
           pool entry names the attachment, and what the thing actually is
           hangs off that. A product reward needs its product too, because
           `readableName()` falls back to it. */
        'poolEntry.reward.reward.product:id,name',
        'redemption.redeemer:id,name',
    ];

    public function __construct(
        public int $id,
        /** What the customer quotes at the counter, and what staff type into Redeem. */
        public string $code,
        public string $customer_name,
        public CustomerType $customer_type,
        public ?string $customer_company,
        public ?string $customer_phone,
        public string $campaign_name,
        public string $reward_name,
        public RewardType $type,
        public string $type_label,
        /** "10%" or "KSh 5,000.00", or null where the reward carries no figure. */
        public ?string $value,
        public string $won_on,
        public ?string $expires_on,
        public RewardResultStatus $status,
        public string $status_label,
        public ?string $redeemed_on,
        public ?string $redeemed_by,
    ) {}

    public static function fromModel(ShuffleResult $result): self
    {
        $customer = $result->session?->customer;
        /* The pool entry names the attachment - how many, for how long - and
           what was actually won is the catalogue row behind it. */
        $reward = $result->poolEntry->reward->reward;
        $redemption = $result->redemption;

        return new self(
            id: $result->id,
            code: $result->code,
            /* `name` before `displayName()` for the same reason the visits list
               does it: a company customer is a person who came in for a
               business, and the business belongs on the line under them. */
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
        );
    }
}
