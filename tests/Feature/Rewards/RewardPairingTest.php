<?php

use App\Enums\RewardType;
use App\Exceptions\ShuffleUnavailableException;
use App\Models\CampaignReward;
use App\Models\Product;
use App\Models\Reward;
use App\Models\RewardCampaign;
use App\Services\Rewards\CampaignService;
use App\Services\Rewards\RewardEligibilityService;
use App\Services\Rewards\ShuffleRewardService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Buy the oven, win the tray
|--------------------------------------------------------------------------
|
| A reward may name the products that put somebody in the running for it. A
| reward naming none is open to any purchase, which is what most of them are -
| so these tests are as much about the unpaired case staying unchanged as they
| are about the paired one working.
|
*/

/** What the claim actually handed over, by catalogue name. */
function wonName(RewardCampaign $campaign, ?Product $bought): string
{
    $result = app(ShuffleRewardService::class)->claim(
        sessionForPurchaseOf($campaign, $bought),
    );

    return $result->poolEntry->reward->reward->name;
}

it('does not hand a paired reward to somebody who bought something else', function () {
    $oven = Product::factory()->create(['name' => 'Gas oven']);
    $fridge = Product::factory()->create(['name' => 'Fridge']);

    $campaign = campaignPairing([
        'Baking tray set' => [5, $oven],
        'Free kitchen audit' => [5, null],
    ]);

    /* Ten units in the drawer and only five of them reachable: somebody who
       bought the fridge can win the audit and nothing else, however many
       times this runs. */
    foreach (range(1, 5) as $ignored) {
        expect(wonName($campaign, $fridge))->toBe('Free kitchen audit');
    }

    $trays = $campaign->rewards()
        ->whereRelation('reward', 'name', 'Baking tray set')
        ->sole();

    expect($trays->availableCount())->toBe(5);
});

it('hands a paired reward to somebody who bought the product it names', function () {
    $oven = Product::factory()->create(['name' => 'Gas oven']);

    /* The only pile in the campaign, so a win proves the pairing let it
       through rather than the shuffle simply missing it. */
    $campaign = campaignPairing(['Baking tray set' => [5, $oven]]);

    expect(wonName($campaign, $oven))->toBe('Baking tray set');
});

it('hands an unpaired reward to anybody at all', function () {
    $anything = Product::factory()->create();

    $campaign = campaignPairing(['Free kitchen audit' => [3, null]]);

    expect(wonName($campaign, $anything))->toBe('Free kitchen audit')
        /* And to a sale with nothing recorded on it, which is most of them. */
        ->and(wonName($campaign, null))->toBe('Free kitchen audit');
});

/**
 * A purchase with no product cannot reach a paired reward. The pairing says
 * "buy the oven"; a sale that never recorded an oven has not met it, and
 * guessing otherwise would hand the tray to anybody.
 */
it('draws only from the unpaired rewards when no product was recorded', function () {
    $oven = Product::factory()->create();

    $campaign = campaignPairing([
        'Baking tray set' => [5, $oven],
        'Free kitchen audit' => [5, null],
    ]);

    foreach (range(1, 5) as $ignored) {
        expect(wonName($campaign, null))->toBe('Free kitchen audit');
    }
});

/**
 * The drawer can be full and still have nothing in it for this customer, which
 * is a different answer from "everything has been won" and has to be refused
 * rather than silently handing over a tray.
 */
it('refuses the shuffle when every reward left is paired to something else', function () {
    $oven = Product::factory()->create();
    $fridge = Product::factory()->create();

    $campaign = campaignPairing(['Baking tray set' => [5, $oven]]);

    expect(fn () => app(ShuffleRewardService::class)->claim(
        sessionForPurchaseOf($campaign, $fridge),
    ))->toThrow(ShuffleUnavailableException::class);

    /* Nothing spent: the customer did nothing wrong and the pool is
       untouched. */
    expect($campaign->availableCount())->toBe(5);
});

it('counts only what a purchase could actually win', function () {
    $oven = Product::factory()->create();
    $fridge = Product::factory()->create();

    $campaign = campaignPairing([
        'Baking tray set' => [5, $oven],
        'Free kitchen audit' => [3, null],
    ]);

    $eligibility = app(RewardEligibilityService::class);

    /* The whole drawer is eight, but what each customer can reach is not. */
    expect($campaign->availableCount())->toBe(8)
        ->and($eligibility->availableCountFor($campaign, $oven->id))->toBe(8)
        ->and($eligibility->availableCountFor($campaign, $fridge->id))->toBe(3)
        ->and($eligibility->availableCountFor($campaign, null))->toBe(3);
});

// -----------------------------------------------------------------------------
// The catalogue
// -----------------------------------------------------------------------------

it('reads a product reward name off the product when none was typed', function () {
    $tray = Product::factory()->create(['name' => 'Baking tray set']);

    $reward = Reward::factory()->create([
        'name' => '',
        'type' => RewardType::Product,
        'product_id' => $tray->id,
    ]);

    expect($reward->readableName())->toBe('Baking tray set')
        ->and($reward->type->isProduct())->toBeTrue();
});

it('keeps a name that was typed, even on a product reward', function () {
    $tray = Product::factory()->create(['name' => 'Baking tray set']);

    $reward = Reward::factory()->create([
        'name' => 'Three-piece bakeware',
        'type' => RewardType::Product,
        'product_id' => $tray->id,
    ]);

    expect($reward->readableName())->toBe('Three-piece bakeware');
});

/**
 * The same reward in two campaigns is the point of a catalogue: describing the
 * audit twice is how two promotions end up offering subtly different ones.
 */
it('lets one catalogue reward serve several campaigns', function () {
    $audit = Reward::factory()->create(['name' => 'Free kitchen audit']);

    $spring = RewardCampaign::factory()->create();
    $autumn = RewardCampaign::factory()->create();

    CampaignReward::factory()->forReward($audit)->quantity(5)
        ->create(['campaign_id' => $spring->id]);
    CampaignReward::factory()->forReward($audit)->quantity(20)
        ->create(['campaign_id' => $autumn->id]);

    app(CampaignService::class)->publish($spring);

    expect($audit->attachments()->count())->toBe(2)
        /* Each campaign holds its own quantity of the one description. */
        ->and($spring->rewards()->sole()->quantity)->toBe(5)
        ->and($autumn->rewards()->sole()->quantity)->toBe(20)
        ->and($spring->refresh()->poolEntries()->count())->toBe(5)
        /* Publishing one campaign loads only that campaign's drawer. */
        ->and($autumn->refresh()->poolEntries()->count())->toBe(0);
});

/**
 * The unique index on `(campaign_id, reward_id)` is the backstop under the
 * form check - a second row for one reward would be a second drawer of the
 * same thing, and every count on the campaign screen would have to know to add
 * them up.
 */
it('refuses the same reward twice in one campaign at the database', function () {
    $campaign = RewardCampaign::factory()->create();
    $reward = Reward::factory()->create();

    CampaignReward::factory()->forReward($reward)->create(['campaign_id' => $campaign->id]);

    expect(fn () => CampaignReward::factory()
        ->forReward($reward)
        ->create(['campaign_id' => $campaign->id]))
        ->toThrow(QueryException::class);
});

/**
 * Deleting a reward a campaign is handing out would leave winners holding a
 * result that can no longer say what they won.
 */
it('will not delete a reward a campaign is holding', function () {
    $campaign = RewardCampaign::factory()->create();
    $reward = Reward::factory()->create();

    CampaignReward::factory()->forReward($reward)->create(['campaign_id' => $campaign->id]);

    expect(fn () => $reward->delete())
        ->toThrow(QueryException::class);
});
