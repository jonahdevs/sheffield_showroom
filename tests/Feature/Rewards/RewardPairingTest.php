<?php

use App\Enums\CampaignStatus;
use App\Enums\Permission;
use App\Enums\RewardType;
use App\Exceptions\ShuffleUnavailableException;
use App\Models\CampaignReward;
use App\Models\Product;
use App\Models\Reward;
use App\Models\RewardCampaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Rewards\CampaignService;
use App\Services\Rewards\RewardEligibilityService;
use App\Services\Rewards\ShuffleRewardService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

# =========================================================================
# Buy the oven, win the tray
# =========================================================================

function wonName(RewardCampaign $campaign, Product ...$bought): string
{
    $result = app(ShuffleRewardService::class)->claim(
        sessionForPurchaseOf($campaign, ...$bought),
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

    # Repeated because the draw is random: ten units loaded, only the five
    # unpaired ones reachable by a fridge.
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

    # Must stay the only reward in the campaign, or a win no longer proves
    # the pairing let it through.
    $campaign = campaignPairing(['Baking tray set' => [5, $oven]]);

    expect(wonName($campaign, $oven))->toBe('Baking tray set');
});

it('hands a paired reward to a receipt that carries other things too', function () {
    $oven = Product::factory()->create(['name' => 'Gas oven']);
    $machine = Product::factory()->create(['name' => 'Coffee machine']);

    $campaign = campaignPairing(['Baking tray set' => [5, $oven]]);

    expect(wonName($campaign, $machine, $oven))->toBe('Baking tray set');
});

it('hands an unpaired reward to anybody at all', function () {
    $anything = Product::factory()->create();

    $campaign = campaignPairing(['Free kitchen audit' => [3, null]]);

    expect(wonName($campaign, $anything))->toBe('Free kitchen audit')
        ->and(wonName($campaign))->toBe('Free kitchen audit');
});

it('draws only from the unpaired rewards when no product was recorded', function () {
    $oven = Product::factory()->create();

    $campaign = campaignPairing([
        'Baking tray set' => [5, $oven],
        'Free kitchen audit' => [5, null],
    ]);

    foreach (range(1, 5) as $ignored) {
        expect(wonName($campaign))->toBe('Free kitchen audit');
    }
});

it('refuses the shuffle when every reward left is paired to something else', function () {
    $oven = Product::factory()->create();
    $fridge = Product::factory()->create();

    $campaign = campaignPairing(['Baking tray set' => [5, $oven]]);

    expect(fn () => app(ShuffleRewardService::class)->claim(
        sessionForPurchaseOf($campaign, $fridge),
    ))->toThrow(ShuffleUnavailableException::class);

    # A refusal must not spend a unit.
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

    expect($campaign->availableCount())->toBe(8)
        ->and($eligibility->availableCountFor($campaign, [$oven->id]))->toBe(8)
        ->and($eligibility->availableCountFor($campaign, [$fridge->id]))->toBe(3)
        ->and($eligibility->availableCountFor($campaign, []))->toBe(3);
});

# =========================================================================
# The catalogue
# =========================================================================

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
        ->and($spring->rewards()->sole()->quantity)->toBe(5)
        ->and($autumn->rewards()->sole()->quantity)->toBe(20)
        ->and($spring->refresh()->poolEntries()->count())->toBe(5)
        ->and($autumn->refresh()->poolEntries()->count())->toBe(0);
});

it('refuses the same reward twice in one campaign at the database', function () {
    $campaign = RewardCampaign::factory()->create();
    $reward = Reward::factory()->create();

    CampaignReward::factory()->forReward($reward)->create(['campaign_id' => $campaign->id]);

    expect(fn () => CampaignReward::factory()
        ->forReward($reward)
        ->create(['campaign_id' => $campaign->id]))
        ->toThrow(QueryException::class);
});

it('will not delete a reward a campaign is holding', function () {
    $campaign = RewardCampaign::factory()->create();
    $reward = Reward::factory()->create();

    CampaignReward::factory()->forReward($reward)->create(['campaign_id' => $campaign->id]);

    expect(fn () => $reward->delete())
        ->toThrow(QueryException::class);
});

# =========================================================================
# Setting the pairing on the campaign form
# =========================================================================

/**
 * A user holding exactly the permissions named, through a role of their own.
 *
 * Self-contained rather than borrowed from `RewardHttpTest`: these helpers are
 * global functions, Pest gives no guarantee which file declares one first,
 * and two files declaring the same name is a fatal error.
 *
 * @param  array<int, Permission>  $permissions
 */
function pairingStaff(array $permissions): User
{
    foreach (Permission::values() as $name) {
        SpatiePermission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'pairing-tester-'.Str::random(8),
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

it('hands the new campaign screen the floor to pair against', function () {
    $oven = Product::factory()->create([
        'name' => 'Gas oven',
        'sku' => 'SHF-OVEN-60',
    ]);

    $this->actingAs(pairingStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]))
        ->get(route('admin.rewards.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/rewards/Form')
            ->has('products', 1)
            ->where('products.0.value', $oven->id)
            ->where('products.0.label', 'Gas oven')
            ->where('products.0.hint', 'SHF-OVEN-60'));
});

it('hands the campaign edit screen the floor to pair against', function () {
    $campaign = RewardCampaign::factory()->create();
    $oven = Product::factory()->create(['name' => 'Gas oven']);

    $this->actingAs(pairingStaff([Permission::RewardsView, Permission::RewardsCampaignsUpdate]))
        ->get(route('admin.rewards.edit', $campaign))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/rewards/Form')
            ->has('products', 1)
            ->where('products.0.value', $oven->id)
            ->where('products.0.label', 'Gas oven'));
});

it('pairs a reward to the products the draft was saved with', function () {
    $campaign = RewardCampaign::factory()->create(['status' => CampaignStatus::Draft]);
    $reward = Reward::factory()->create();
    $oven = Product::factory()->create();

    CampaignReward::factory()->forReward($reward)->create(['campaign_id' => $campaign->id]);

    $this->actingAs(pairingStaff([Permission::RewardsView, Permission::RewardsCampaignsUpdate]))
        ->patch(route('admin.rewards.update', $campaign), [
            'name' => $campaign->name,
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                [
                    'reward_id' => $reward->id,
                    'quantity' => 5,
                    'qualifying_product_ids' => [$oven->id],
                ],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect($campaign->rewards()->sole()->qualifyingProducts->pluck('id')->all())
        ->toBe([$oven->id]);
});

/**
 * `writeRewards()` only calls `sync()` when the form named something, so
 * clearing falls out of `update()` rebuilding the attachments and cascading
 * `campaign_reward_product`. An early return in `update()` for an unchanged
 * drawer would silently strand every cleared pairing - hence a test of its own.
 */
it('unpairs a reward when the draft is saved with the picker emptied', function () {
    $campaign = RewardCampaign::factory()->create(['status' => CampaignStatus::Draft]);
    $reward = Reward::factory()->create();
    $oven = Product::factory()->create();

    CampaignReward::factory()
        ->forReward($reward)
        ->qualifyingFor($oven)
        ->create(['campaign_id' => $campaign->id]);

    $this->actingAs(pairingStaff([Permission::RewardsView, Permission::RewardsCampaignsUpdate]))
        ->patch(route('admin.rewards.update', $campaign), [
            'name' => $campaign->name,
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                [
                    'reward_id' => $reward->id,
                    'quantity' => 5,
                    'qualifying_product_ids' => [],
                ],
            ],
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseEmpty('campaign_reward_product');

    expect($campaign->rewards()->sole()->qualifiesFor([]))->toBeTrue();
});

it('still names a paired product that has been withdrawn from the floor', function () {
    $campaign = RewardCampaign::factory()->create();
    $oven = Product::factory()->create(['name' => 'Gas oven']);

    CampaignReward::factory()
        ->qualifyingFor($oven)
        ->create(['campaign_id' => $campaign->id]);

    $oven->delete();

    $this->actingAs(pairingStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.edit', $campaign))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            # Out of the pickable list, but still named on the reward: a save
            # that lost the name would post the pairing back empty.
            ->has('products', 0)
            ->where('campaign.rewards.0.qualifying_products.0.id', $oven->id)
            ->where('campaign.rewards.0.qualifying_products.0.name', 'Gas oven'));
});
