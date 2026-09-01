<?php

use App\Enums\CampaignStatus;
use App\Enums\RewardType;
use App\Models\CampaignReward;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\RewardCampaign;
use App\Models\ShuffleSession;
use App\Services\Rewards\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

# =========================================================================
# Test Case
# =========================================================================

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

# =========================================================================
# Expectations
# =========================================================================

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

# =========================================================================
# Functions
# =========================================================================

/**
 * A published campaign holding exactly the pool it says it does.
 *
 * Declared here rather than in one test file: several need it, and a helper
 * declared twice across files is a fatal redeclaration.
 *
 * @param  array<string, int>  $quantities  reward name to how many units
 */
function campaignHolding(array $quantities, ?float $minimum = null): RewardCampaign
{
    $campaign = RewardCampaign::factory()->create([
        'status' => CampaignStatus::Draft,
        'minimum_purchase_amount' => $minimum,
    ]);

    foreach ($quantities as $name => $quantity) {
        # The name belongs to the catalogue reward, so it goes through
        # `ofType()`; the attachment only knows how many there are.
        CampaignReward::factory()
            ->quantity($quantity)
            ->ofType(RewardType::KitchenAudit, $name)
            ->create(['campaign_id' => $campaign->id]);
    }

    app(CampaignService::class)->publish($campaign);

    return $campaign->refresh();
}

/**
 * Same shape as `campaignHolding`, except each pile names the product that
 * qualifies for it. A pile mapped to `null` is left unpaired.
 *
 * @param  array<string, array{0: int, 1: ?Product}>  $piles  reward name to [quantity, qualifying product]
 */
function campaignPairing(array $piles, ?float $minimum = null): RewardCampaign
{
    $campaign = RewardCampaign::factory()->create([
        'status' => CampaignStatus::Draft,
        'minimum_purchase_amount' => $minimum,
    ]);

    foreach ($piles as $name => [$quantity, $product]) {
        $factory = CampaignReward::factory()
            ->quantity($quantity)
            ->ofType(RewardType::KitchenAudit, $name);

        if ($product !== null) {
            $factory = $factory->qualifyingFor($product);
        }

        $factory->create(['campaign_id' => $campaign->id]);
    }

    app(CampaignService::class)->publish($campaign);

    return $campaign->refresh();
}

/** A pending turn on this campaign, with no purchase behind it. */
function sessionOn(RewardCampaign $campaign): ShuffleSession
{
    return ShuffleSession::factory()->create(['campaign_id' => $campaign->id]);
}

/**
 * A pending turn earned by a purchase of these products. Passing none is the
 * sale that recorded nothing, which is the common case.
 */
function sessionForPurchaseOf(RewardCampaign $campaign, Product ...$products): ShuffleSession
{
    $purchase = Purchase::factory()->create();

    $purchase->products()->sync(array_map(
        fn (Product $product): int => $product->id,
        $products,
    ));

    return ShuffleSession::factory()->create([
        'campaign_id' => $campaign->id,
        'customer_id' => $purchase->customer_id,
        'purchase_id' => $purchase->id,
    ]);
}
