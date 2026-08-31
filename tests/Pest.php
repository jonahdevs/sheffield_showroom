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

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A published reward campaign holding exactly the pool it says it does.
 *
 * Shared rather than declared in one test file, because more than one of them
 * needs a campaign with a known drawer and Pest gives no guarantee about which
 * file loads first.
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
        /* The name is the catalogue's now, so it is set through `ofType()`
           rather than on the attachment - the attachment only knows how many
           there are. */
        CampaignReward::factory()
            ->quantity($quantity)
            ->ofType(RewardType::KitchenAudit, $name)
            ->create(['campaign_id' => $campaign->id]);
    }

    app(CampaignService::class)->publish($campaign);

    return $campaign->refresh();
}

/**
 * A published campaign whose rewards are paired to products.
 *
 * Same shape as `campaignHolding`, except each pile names the product somebody
 * must have bought to be in the running for it. A pile mapped to `null` is
 * left unpaired, which is what most rewards are.
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
 * A pending turn earned by a purchase of this product.
 *
 * The purchase is what carries the product, and the product is what decides
 * which paired rewards the turn can reach - so a test about pairing needs the
 * whole chain rather than a bare session.
 */
function sessionForPurchaseOf(RewardCampaign $campaign, ?Product $product): ShuffleSession
{
    $purchase = Purchase::factory()->create([
        'product_id' => $product?->id,
    ]);

    return ShuffleSession::factory()->create([
        'campaign_id' => $campaign->id,
        'customer_id' => $purchase->customer_id,
        'purchase_id' => $purchase->id,
    ]);
}
