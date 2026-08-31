<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\RewardCampaign;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A campaign as the customer's own page is allowed to describe it.
 *
 * Deliberately not `RewardCampaignData`, which carries `loaded`, `available`,
 * `claimed` and `void`. This page is opened with nothing but a token - a
 * photographed QR code reaches it - and publishing live inventory there lets
 * anybody work out the odds before they tap. The architecture document's own
 * non-goals say this must not behave like a betting product, and a remaining
 * count is the shortest route to it behaving like one.
 *
 * So: what the promotion is, when it runs, and what it takes to qualify. Never
 * how much of it is left.
 */
#[TypeScript(location: ['App', 'Data'])]
class ShuffleCampaignData extends Data
{
    public function __construct(
        public string $name,
        public ?string $description,
        /** Written out for reading, not for arithmetic. */
        public ?string $runs_from,
        public ?string $runs_to,
        /** "KSh 100,000.00", or null when any completed purchase qualifies. */
        public ?string $minimum_purchase,
        public int $shuffles_per_customer,
    ) {}

    public static function fromModel(RewardCampaign $campaign): self
    {
        $minimum = $campaign->minimum_purchase_amount;

        return new self(
            name: $campaign->name,
            description: $campaign->description,
            runs_from: $campaign->starts_at?->toFormattedDayDateString(),
            runs_to: $campaign->ends_at?->toFormattedDayDateString(),
            minimum_purchase: $minimum === null
                ? null
                : 'KSh '.number_format((float) $minimum, 2),
            shuffles_per_customer: $campaign->max_shuffles_per_customer,
        );
    }
}
