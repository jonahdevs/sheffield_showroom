<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\RewardCampaign;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# Never `RewardCampaignData` here, and never a remaining count: this page opens
# on nothing but a token, and publishing live inventory lets anybody work out
# the odds before they tap.
#[TypeScript(location: ['App', 'Data'])]
class ShuffleCampaignData extends Data
{
    public function __construct(
        public string $name,
        public ?string $description,
        /**
         * Whether the promotion is open right now, and nothing finer. The status
         * itself stays in: that a campaign is *paused* rather than finished is the
         * showroom's business, and this page is read by anybody holding a link.
         */
        public bool $is_running,
        public ?string $runs_from,
        public ?string $runs_to,
        public ?string $minimum_purchase,
        public int $shuffles_per_customer,
        /** @var list<string> */
        public array $terms,
    ) {}

    /**
     * The shuffle-count sentence is composed, not written down: a printed term
     * that contradicts the rule the software enforces is worse than no term.
     *
     * @return list<string>
     */
    private static function termsFor(RewardCampaign $campaign): array
    {
        $shuffles = $campaign->max_shuffles_per_customer;

        return [
            'Rewards are non-transferable.',
            $shuffles === 1
                ? 'Each customer can shuffle only once.'
                : "Each customer can shuffle up to {$shuffles} times.",
            'Rewards must be redeemed within the period stated on the reward.',
            'Sheffield Africa reserves the right to modify or cancel this campaign.',
        ];
    }

    public static function fromModel(RewardCampaign $campaign): self
    {
        $minimum = $campaign->minimum_purchase_amount;

        return new self(
            name: $campaign->name,
            description: $campaign->description,
            is_running: $campaign->isRunning(),
            # `j M Y`, not the weekday form used for a single date elsewhere: these
            # two are printed as one range on a narrow panel, and "Fri, Oct 3, 2026"
            # twice over wraps onto three lines.
            #
            # A campaign published with no start date runs from the moment it was
            # published - `CampaignService::statusOnPublication` reads it exactly that
            # way - so the row's own creation date stands in rather than leaving the
            # panel to say "Until the 9th" and nothing about when it opened.
            runs_from: ($campaign->starts_at ?? $campaign->created_at)?->format('j M Y'),
            runs_to: $campaign->ends_at?->format('j M Y'),
            minimum_purchase: $minimum === null
                ? null
                : 'KSh '.number_format((float) $minimum, 2),
            shuffles_per_customer: $campaign->max_shuffles_per_customer,
            terms: self::termsFor($campaign),
        );
    }
}
