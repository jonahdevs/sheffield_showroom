<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\ShuffleSessionStatus;
use App\Models\Purchase;
use App\Models\RewardCampaign;
use Carbon\CarbonImmutable;

/**
 * Whether a purchase has earned somebody a turn.
 *
 * Kept in one place on purpose. Every one of these questions is the sort that
 * gets asked again on a screen, in a controller, and in the button that
 * decides whether to show a QR code - and a rule that lives in three places is
 * a rule that will disagree with itself the first time somebody changes it.
 *
 * The answer carries its reason. A member of staff standing in front of a
 * customer needs to know *why* there is no reward, and "not eligible" sends
 * them to find a manager.
 */
class RewardEligibilityService
{
    /**
     * The campaign this purchase would be measured against, or null when
     * nothing is running.
     *
     * At most one campaign is active at a time - `CampaignService` holds that -
     * so this takes the first deterministically rather than trusting that only
     * one came back.
     */
    public function campaignFor(Purchase $purchase): ?RewardCampaign
    {
        return RewardCampaign::query()
            ->running($purchase->purchased_at ?? CarbonImmutable::now())
            ->first();
    }

    /**
     * Why this purchase cannot earn a turn, or null when it can.
     *
     * Ordered the way a person would ask: is there a promotion at all, does
     * this sale count, is it big enough, have they had their turn, and is
     * there anything left to win. The cheapest questions come first, so the
     * two that touch the database only run when they have to.
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

        /* One turn per sale. The unique index on `shuffle_sessions.purchase_id`
           is what actually enforces it - this is so the screen can say so
           before somebody presses the button. */
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

        return null;
    }

    /** Whether a turn can be minted for this purchase. */
    public function qualifies(Purchase $purchase, ?RewardCampaign $campaign = null): bool
    {
        return $this->refusalFor($purchase, $campaign) === null;
    }

    /**
     * How many turns this customer has had in this campaign.
     *
     * A cancelled turn does not count against them - it was taken back by
     * staff, not used - but an expired one does: they were given their chance
     * and the window closed.
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
