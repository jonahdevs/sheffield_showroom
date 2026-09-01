<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\PoolEntryStatus;
use App\Enums\ShuffleSessionStatus;
use App\Models\CampaignReward;
use App\Models\Purchase;
use App\Models\RewardCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a purchase has earned somebody a turn. The one place that answers it, so the
 * screen, the controller and the claim cannot disagree about the same receipt.
 */
class RewardEligibilityService
{
    /**
     * At most one campaign runs at a time - `CampaignService` holds that invariant - so
     * this takes the first rather than trusting that only one came back.
     */
    public function campaignFor(Purchase $purchase): ?RewardCampaign
    {
        return RewardCampaign::query()
            ->running($purchase->purchased_at ?? CarbonImmutable::now())
            ->first();
    }

    /**
     * Why this purchase cannot earn a turn, or null when it can. Cheapest checks first.
     */
    public function refusalFor(Purchase $purchase, ?RewardCampaign $campaign = null): ?string
    {
        $campaign ??= $this->campaignFor($purchase);

        if ($campaign === null) {
            return 'No reward campaign is running.';
        }

        if (! $campaign->isRunning($purchase->purchased_at ?? CarbonImmutable::now())) {
            return 'The reward campaign was not running when this purchase was made.';
        }

        if (! $purchase->status->isQualifying()) {
            return 'Only a completed purchase earns a shuffle.';
        }

        $minimum = $campaign->minimum_purchase_amount;

        if ($minimum !== null && (float) $purchase->amount < (float) $minimum) {
            return sprintf(
                'This campaign starts at KSh %s, and this purchase is KSh %s.',
                number_format((float) $minimum, 2),
                number_format((float) $purchase->amount, 2),
            );
        }

        # One turn per sale is enforced by the unique index on
        # `shuffle_sessions.purchase_id`; this only lets the screen say so first.
        if ($purchase->shuffleSession()->exists()) {
            return 'This purchase has already been given a shuffle.';
        }

        $held = $this->turnsTaken($campaign, $purchase->customer_id);

        if ($held >= $campaign->max_shuffles_per_customer) {
            return sprintf(
                'This customer has had %s of %s shuffles in this campaign.',
                $held,
                $campaign->max_shuffles_per_customer,
            );
        }

        if ($campaign->availableCount() === 0) {
            return 'Every reward in this campaign has been won.';
        }

        $productIds = $this->productIdsOn($purchase);

        if ($this->availableCountFor($campaign, $productIds) === 0) {
            return $productIds === []
                ? 'The rewards left in this campaign are all paired to a product, and nothing was recorded as bought on this purchase.'
                : 'Nothing left in this campaign is paired with anything this customer bought.';
        }

        return null;
    }

    /**
     * Reads the loaded relation when there is one - a list that does not eager-load
     * `products` pays a query per row. Null is a staff-run turn and names nothing.
     *
     * @return array<int, int>
     */
    public function productIdsOn(?Purchase $purchase): array
    {
        if ($purchase === null) {
            return [];
        }

        $products = $purchase->relationLoaded('products')
            ? $purchase->products->pluck('id')
            : $purchase->products()->pluck('products.id');

        return $products->map(fn (int|string $id): int => (int) $id)->all();
    }

    /**
     * A reward naming no products qualifies against any purchase, and that silence is
     * the common case. A reward that does name products qualifies on *any one* of them.
     * An empty $productIds draws only from the unpaired set.
     *
     * Called before `ShuffleRewardService` opens its locking statement, so that
     * statement stays one table and one index. Do not fold it into the claim.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public function qualifyingRewardIds(RewardCampaign $campaign, array $productIds): array
    {
        return CampaignReward::query()
            ->where('campaign_id', $campaign->id)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($productIds): void {
                $query->whereDoesntHave('qualifyingProducts');

                if ($productIds !== []) {
                    # Qualified: the relation joins the pivot, and `product_id` is a
                    # column on both sides of it.
                    $query->orWhereHas(
                        'qualifyingProducts',
                        fn (Builder $products) => $products->whereIn('products.id', $productIds),
                    );
                }
            })
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * Not `RewardCampaign::availableCount()`, which counts the whole drawer - a campaign
     * full of trays has nothing for somebody who did not buy an oven.
     *
     * @param  array<int, int>  $productIds
     */
    public function availableCountFor(RewardCampaign $campaign, array $productIds): int
    {
        $rewardIds = $this->qualifyingRewardIds($campaign, $productIds);

        if ($rewardIds === []) {
            return 0;
        }

        return $campaign->poolEntries()
            ->where('status', PoolEntryStatus::Available)
            ->whereIn('campaign_reward_id', $rewardIds)
            ->count();
    }

    public function qualifies(Purchase $purchase, ?RewardCampaign $campaign = null): bool
    {
        return $this->refusalFor($purchase, $campaign) === null;
    }

    /**
     * A cancelled turn does not count against the customer - staff took it back - but an
     * expired one does: they were given their chance and the window closed.
     */
    private function turnsTaken(RewardCampaign $campaign, int $customerId): int
    {
        return $campaign->sessions()
            ->where('customer_id', $customerId)
            ->whereIn('status', [
                ShuffleSessionStatus::Pending,
                ShuffleSessionStatus::Shuffled,
                ShuffleSessionStatus::Expired,
            ])
            ->count();
    }
}
