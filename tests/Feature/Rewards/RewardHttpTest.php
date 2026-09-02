<?php

use App\Enums\CampaignStatus;
use App\Enums\Permission;
use App\Enums\PurchaseStatus;
use App\Enums\RewardResultStatus;
use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Enums\ShuffleSessionStatus;
use App\Models\CampaignReward;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Reward;
use App\Models\RewardCampaign;
use App\Models\RewardPoolEntry;
use App\Models\Role;
use App\Models\ShuffleResult;
use App\Models\ShuffleSession;
use App\Models\User;
use App\Models\Visit;
use App\Services\Rewards\ShuffleRewardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

/**
 * A user holding exactly the permissions named, through a role of their own.
 *
 * Self-contained rather than calling the equivalent helper in another test
 * file: these helpers are global functions, Pest gives no guarantee which
 * file declares one first, and two files declaring the same name is a fatal
 * error.
 *
 * @param  array<int, Permission>  $permissions
 */
function rewardsStaff(array $permissions): User
{
    foreach (Permission::values() as $name) {
        SpatiePermission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'rewards-tester-'.Str::random(8),
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

# =========================================================================
# Who may reach what
# =========================================================================

it('keeps the campaign screens behind rewards.view', function () {
    $this->actingAs(rewardsStaff([Permission::PurchasesViewAny]))
        ->get(route('admin.rewards.index'))
        ->assertForbidden();
});

it('refuses to create a campaign without rewards.campaigns.create', function () {
    $this->actingAs(rewardsStaff([Permission::RewardsView]))
        ->post(route('admin.rewards.store'), [
            'name' => 'Sneaky',
            'max_shuffles_per_customer' => 1,
            'rewards' => [],
        ])
        ->assertForbidden();
});

it('refuses to publish without rewards.campaigns.update', function () {
    $campaign = RewardCampaign::factory()->create(['status' => CampaignStatus::Draft]);

    $this->actingAs(rewardsStaff([Permission::RewardsView]))
        ->post(route('admin.rewards.publish', $campaign))
        ->assertForbidden();
});

it('refuses to redeem without rewards.redeem', function () {
    $campaign = campaignHolding(['Audit' => 5]);
    $result = app(ShuffleRewardService::class)->claim(sessionOn($campaign));

    $this->actingAs(rewardsStaff([Permission::RewardsView]))
        ->post(route('admin.rewards.redeem.store'), ['code' => $result->code])
        ->assertForbidden();

    expect($result->refresh()->status)->toBe(RewardResultStatus::Unredeemed);
});

it('refuses to mint a turn without rewards.shuffle', function () {
    campaignHolding(['Audit' => 5]);
    $purchase = Purchase::factory()->create();

    $this->actingAs(rewardsStaff([Permission::PurchasesViewAny]))
        ->post(route('admin.shuffles.store', $purchase))
        ->assertForbidden();

    expect(ShuffleSession::query()->count())->toBe(0);
});

# =========================================================================
# Building a campaign
# =========================================================================

it('creates a campaign with its drawer in one go', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $discount = Reward::factory()->discount(10)->create();
    $installation = Reward::factory()
        ->ofType(RewardType::Installation, 'Free installation')
        ->create();

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'August showroom rewards',
            'max_shuffles_per_customer' => 1,
            'minimum_purchase_amount' => '100000.00',
            'rewards' => [
                ['reward_id' => $discount->id, 'quantity' => 20],
                ['reward_id' => $installation->id, 'quantity' => 15],
            ],
        ])
        ->assertSessionHasNoErrors();

    $campaign = RewardCampaign::query()->sole();

    expect($campaign->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->rewards()->count())->toBe(2)
        ->and($campaign->poolEntries()->count())->toBe(0);
});

it('takes the validity the catalogue suggests when the form leaves it out', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $suggests = Reward::factory()->create(['default_validity_days' => 45]);
    $overridden = Reward::factory()->discount()->create(['default_validity_days' => 45]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'Deadlines',
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                ['reward_id' => $suggests->id, 'quantity' => 5],
                ['reward_id' => $overridden->id, 'quantity' => 5, 'validity_days' => 7],
            ],
        ])
        ->assertSessionHasNoErrors();

    $campaign = RewardCampaign::query()->sole();

    expect($campaign->rewards()->where('reward_id', $suggests->id)->sole()->validity_days)->toBe(45)
        ->and($campaign->rewards()->where('reward_id', $overridden->id)->sole()->validity_days)->toBe(7);
});

it('refuses the same reward twice on one campaign', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $reward = Reward::factory()->create();

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'Doubled up',
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                ['reward_id' => $reward->id, 'quantity' => 5],
                ['reward_id' => $reward->id, 'quantity' => 5],
            ],
        ])
        ->assertSessionHasErrors('rewards.1.reward_id');

    expect(RewardCampaign::query()->count())->toBe(0);
});

it('refuses to put a retired reward into a campaign', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $retired = Reward::factory()->inactive()->create();

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'Digging one out',
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                ['reward_id' => $retired->id, 'quantity' => 5],
            ],
        ])
        ->assertSessionHasErrors('rewards.0.reward_id');
});

it('records the products a reward is paired to', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $reward = Reward::factory()->create();
    $oven = Product::factory()->create();

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'Paired',
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

    $attachment = RewardCampaign::query()->sole()->rewards()->sole();

    expect($attachment->qualifyingProducts->pluck('id')->all())->toBe([$oven->id]);
});

it('runs an end date to the close of its day', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'Ends on the 28th',
            'max_shuffles_per_customer' => 1,
            'starts_at' => '2026-09-01',
            'ends_at' => '2026-09-28',
            'rewards' => [
                ['reward_id' => Reward::factory()->create()->id, 'quantity' => 5],
            ],
        ])
        ->assertSessionHasNoErrors();

    $campaign = RewardCampaign::query()->sole();

    expect($campaign->starts_at->toDateTimeString())->toBe('2026-09-01 00:00:00')
        ->and($campaign->ends_at->toDateTimeString())->toBe('2026-09-28 23:59:59')
        ->and($campaign->ends_at->isAfter(CarbonImmutable::parse('2026-09-28 17:00')))
        ->toBeTrue();
});

it('leaves an end date alone when it already carries a time', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'Ends at noon',
            'max_shuffles_per_customer' => 1,
            'ends_at' => '2026-09-28 12:00:00',
            'rewards' => [
                ['reward_id' => Reward::factory()->create()->id, 'quantity' => 5],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(RewardCampaign::query()->sole()->ends_at->toDateTimeString())
        ->toBe('2026-09-28 12:00:00');
});

it('refuses a campaign with no rewards in it', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'Empty',
            'max_shuffles_per_customer' => 1,
            'rewards' => [],
        ])
        ->assertSessionHasErrors('rewards');
});

it('shows no figure for a reward whose number has no unit', function () {
    $ambiguous = Reward::factory()->create([
        'type' => RewardType::Discount,
        'value' => '10.00',
        'value_unit' => null,
    ]);

    expect($ambiguous->readableValue())->toBeNull();

    $ambiguous->update(['value_unit' => RewardValueUnit::Percentage]);

    expect($ambiguous->fresh()->readableValue())->toBe('10%');
});

it('publishes a draft and loads the pool', function () {
    $actor = rewardsStaff([
        Permission::RewardsView,
        Permission::RewardsCampaignsCreate,
        Permission::RewardsCampaignsUpdate,
    ]);

    $campaign = RewardCampaign::factory()->create(['status' => CampaignStatus::Draft]);
    CampaignReward::factory()->quantity(30)->create(['campaign_id' => $campaign->id]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.publish', $campaign))
        ->assertSessionHasNoErrors();

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Active)
        ->and($campaign->poolEntries()->count())->toBe(30);
});

# -------------------------------------------------------------------------
# Editing the drawer of a campaign that is already running
# -------------------------------------------------------------------------
#
# An attachment may be added or removed at any time; one with a claimed unit
# may never be removed. Everything below is that one rule.

it('ignores a quantity posted at an attachment the campaign already holds', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsUpdate]);
    $campaign = campaignHolding(['Audit' => 10]);
    $attachment = $campaign->rewards()->sole();

    $this->actingAs($actor)
        ->patch(route('admin.rewards.update', $campaign), [
            'name' => 'Renamed',
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                [
                    'reward_id' => $attachment->reward_id,
                    'quantity' => 500,
                    'validity_days' => 21,
                ],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect($campaign->refresh()->name)->toBe('Renamed')
        ->and($attachment->refresh()->quantity)->toBe(10)
        ->and($campaign->poolEntries()->count())->toBe(10)
        # The rest of the row is still the administrator's to correct.
        ->and($attachment->validity_days)->toBe(21);
});

it('keeps the attachment id of a reward it is only editing', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsUpdate]);
    $campaign = campaignHolding(['Audit' => 4, 'Tray' => 6]);

    $ids = $campaign->rewards()->pluck('id', 'reward_id')->all();

    $this->actingAs($actor)
        ->patch(route('admin.rewards.update', $campaign), [
            'name' => $campaign->name,
            'max_shuffles_per_customer' => 1,
            'rewards' => array_map(
                fn (int $rewardId): array => [
                    'reward_id' => $rewardId,
                    'quantity' => 1,
                    'validity_days' => 30,
                ],
                array_keys($ids),
            ),
        ])
        ->assertSessionHasNoErrors();

    expect($campaign->rewards()->pluck('id', 'reward_id')->all())->toBe($ids)
        # Recreating the rows would have repointed every unit at a new id.
        ->and($campaign->poolEntries()->count())->toBe(10);
});

it('writes the units of a reward added to a published campaign, and only those', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsUpdate]);
    $campaign = campaignHolding(['Audit' => 10]);
    $held = $campaign->rewards()->sole();
    $added = Reward::factory()->discount(15)->create();

    $this->actingAs($actor)
        ->patch(route('admin.rewards.update', $campaign), [
            'name' => $campaign->name,
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                ['reward_id' => $held->reward_id, 'quantity' => 10],
                ['reward_id' => $added->id, 'quantity' => 3],
            ],
        ])
        ->assertSessionHasNoErrors();

    $attachment = $campaign->rewards()->where('reward_id', $added->id)->sole();

    expect($attachment->poolEntries()->count())->toBe(3)
        ->and($held->poolEntries()->count())->toBe(10)
        ->and($campaign->poolEntries()->count())->toBe(13);
});

it('removes an attachment nobody has won, and the units behind it', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsUpdate]);
    $campaign = campaignHolding(['Audit' => 5, 'Tray' => 7]);

    $kept = $campaign->rewards()->whereRelation('reward', 'name', 'Audit')->sole();
    $dropped = $campaign->rewards()->whereRelation('reward', 'name', 'Tray')->sole();

    $this->actingAs($actor)
        ->patch(route('admin.rewards.update', $campaign), [
            'name' => $campaign->name,
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                ['reward_id' => $kept->reward_id, 'quantity' => 5],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(CampaignReward::query()->whereKey($dropped->id)->exists())->toBeFalse()
        # Cascaded by `reward_pool_entries.campaign_reward_id`, not by hand.
        ->and(RewardPoolEntry::query()->where('campaign_reward_id', $dropped->id)->count())->toBe(0)
        ->and($campaign->poolEntries()->count())->toBe(5);
});

it('refuses to remove an attachment somebody has already won, and names it', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsUpdate]);
    $campaign = campaignHolding(['Free kitchen audit' => 5, 'Tray' => 5]);

    $won = app(ShuffleRewardService::class)->claim(sessionOn($campaign));
    $winner = $won->poolEntry->reward;
    $other = $campaign->rewards()->whereKeyNot($winner->id)->sole();

    $this->actingAs($actor)
        ->patch(route('admin.rewards.update', $campaign), [
            'name' => $campaign->name,
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                ['reward_id' => $other->reward_id, 'quantity' => 5],
            ],
        ])
        ->assertSessionHasErrors([
            'rewards' => $winner->reward->readableName().' has already been won by a customer and cannot be taken out of the campaign. Void its remaining units instead.',
        ]);

    expect($campaign->rewards()->count())->toBe(2)
        ->and($campaign->poolEntries()->count())->toBe(10);
});

it('lets the catalogue delete a reward once the last campaign holding it lets go', function () {
    $actor = rewardsStaff([
        Permission::RewardsView,
        Permission::RewardsCampaignsUpdate,
        Permission::RewardsCatalogueDelete,
    ]);

    $campaign = campaignHolding(['Audit' => 5, 'Tray' => 5]);
    $dropped = $campaign->rewards()->whereRelation('reward', 'name', 'Tray')->sole()->reward;
    $kept = $campaign->rewards()->whereRelation('reward', 'name', 'Audit')->sole();

    $this->actingAs($actor)
        ->delete(route('admin.rewards.catalogue.destroy', $dropped))
        ->assertForbidden();

    $this->actingAs($actor)
        ->patch(route('admin.rewards.update', $campaign), [
            'name' => $campaign->name,
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                ['reward_id' => $kept->reward_id, 'quantity' => 5],
            ],
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($actor)
        ->delete(route('admin.rewards.catalogue.destroy', $dropped))
        ->assertSessionHasNoErrors();

    expect(Reward::query()->whereKey($dropped->id)->exists())->toBeFalse();
});

it('deletes a campaign nobody ever shuffled, pool and all', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsDelete]);
    $campaign = campaignHolding(['Audit' => 5]);

    $this->actingAs($actor)
        ->delete(route('admin.rewards.destroy', $campaign))
        ->assertSessionHasNoErrors();

    expect(RewardCampaign::query()->whereKey($campaign->id)->exists())->toBeFalse()
        ->and(RewardPoolEntry::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

it('will not delete a campaign that has been shuffled', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsDelete]);
    $campaign = campaignHolding(['Audit' => 5]);
    sessionOn($campaign);

    $this->actingAs($actor)
        ->delete(route('admin.rewards.destroy', $campaign))
        ->assertForbidden();

    expect(RewardCampaign::query()->whereKey($campaign->id)->exists())->toBeTrue();
});

# =========================================================================
# Giving a turn, and running it
# =========================================================================

it('mints a turn for a qualifying purchase and lands on the QR screen', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsShuffle]);
    campaignHolding(['Audit' => 5]);
    $purchase = Purchase::factory()->create();

    $session = null;

    $this->actingAs($actor)
        ->post(route('admin.shuffles.store', $purchase))
        ->assertRedirect();

    $session = ShuffleSession::query()->sole();

    expect($session->purchase_id)->toBe($purchase->id);

    $this->actingAs($actor)
        ->get(route('admin.shuffles.show', $session))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/rewards/Shuffle')
            ->where('session.is_shuffleable', true)
            ->where('session.url', route('rewards.shuffle.show', $session->token)));
});

it('says why a purchase cannot be given a turn', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsShuffle]);
    campaignHolding(['Audit' => 5], minimum: 100_000);
    $purchase = Purchase::factory()->worth(500)->create();

    $this->actingAs($actor)
        ->from(route('admin.purchases.index'))
        ->post(route('admin.shuffles.store', $purchase))
        ->assertSessionHasErrors('shuffle');

    expect(ShuffleSession::query()->count())->toBe(0);
});

it('runs the staff fallback with the same service the customer uses', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsShuffle]);
    $campaign = campaignHolding(['Audit' => 5]);
    $session = sessionOn($campaign);

    $this->actingAs($actor)
        ->post(route('admin.shuffles.run', $session))
        ->assertSessionHasNoErrors();

    expect($session->refresh()->status)->toBe(ShuffleSessionStatus::Shuffled)
        ->and($campaign->availableCount())->toBe(4);
});

# =========================================================================
# The public page
# =========================================================================

it('opens the customer page with nothing but a token', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 5]);
    $session = sessionOn($campaign);

    $this->get(route('rewards.shuffle.show', $session->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('rewards/Shuffle')
            ->where('state', 'ready')
            ->where('campaign.name', $campaign->name)
            ->missing('campaign.available')
            ->missing('campaign.loaded')
            ->has('cards', 1)
            ->missing('customer')
            ->missing('available'));
});

it('shuffles from the public page and reveals the reward', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 5]);
    $session = sessionOn($campaign);

    $this->post(route('rewards.shuffle.store', $session->token))
        ->assertRedirect(route('rewards.shuffle.show', $session->token));

    $result = ShuffleResult::query()->sole();

    $this->get(route('rewards.shuffle.show', $session->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('state', 'won')
            ->where('reward.code', $result->code)
            ->where('reward.name', 'Free kitchen audit')
            # The reveal must not name the person holding the phone.
            ->where('reward.customer_name', null));

    expect($session->refresh()->status)->toBe(ShuffleSessionStatus::Shuffled)
        ->and($campaign->availableCount())->toBe(4);
});

it('gives one reward however many times the button is pressed', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 5]);
    $session = sessionOn($campaign);

    $this->post(route('rewards.shuffle.store', $session->token));
    $this->post(route('rewards.shuffle.store', $session->token));
    $this->post(route('rewards.shuffle.store', $session->token));

    expect(ShuffleResult::query()->count())->toBe(1)
        ->and($campaign->availableCount())->toBe(4);
});

it('404s on a token that names nothing', function () {
    $this->get(route('rewards.shuffle.show', Str::random(64)))->assertNotFound();
});

it('draws a state rather than an error for a link that ran out', function () {
    $campaign = campaignHolding(['Audit' => 5]);
    $session = ShuffleSession::factory()->expired()->create(['campaign_id' => $campaign->id]);

    $this->get(route('rewards.shuffle.show', $session->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'expired'));
});

it('draws the empty-drawer state without spending the turn', function () {
    $campaign = campaignHolding(['Audit' => 1]);
    app(ShuffleRewardService::class)->claim(sessionOn($campaign));

    $unlucky = sessionOn($campaign);

    $this->get(route('rewards.shuffle.show', $unlucky->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'pool_empty'));

    expect($unlucky->refresh()->status)->toBe(ShuffleSessionStatus::Pending);
});

it('throttles the public endpoint', function () {
    $campaign = campaignHolding(['Audit' => 50]);
    $session = sessionOn($campaign);

    foreach (range(1, 10) as $ignored) {
        $this->get(route('rewards.shuffle.show', $session->token))->assertOk();
    }

    $this->get(route('rewards.shuffle.show', $session->token))->assertStatus(429);
});

# =========================================================================
# Redeeming
# =========================================================================

it('finds a reward by its code and hands it over', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsRedeem]);
    $campaign = campaignHolding(['Audit' => 5]);
    $result = app(ShuffleRewardService::class)->claim(sessionOn($campaign));

    $this->actingAs($actor)
        ->get(route('admin.rewards.winners.index', ['redeem' => $result->code]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/rewards/Winners')
            ->where('redeem.searched', true)
            ->where('redeem.reward.code', $result->code)
            ->where('redeem.can_redeem', true));

    $this->actingAs($actor)
        ->post(route('admin.rewards.redeem.store'), [
            'code' => $result->code,
            'notes' => 'Fitted on site.',
        ])
        ->assertSessionHasNoErrors();

    expect($result->refresh()->status)->toBe(RewardResultStatus::Redeemed);
});

it('says so plainly when a code is not one', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsRedeem]);

    $this->actingAs($actor)
        ->from(route('admin.rewards.winners.index'))
        ->post(route('admin.rewards.redeem.store'), ['code' => 'SHF-ZZZZZZ'])
        ->assertSessionHasErrors('code');
});

it('leaves the counter closed until a code is asked about', function () {
    $this->actingAs(rewardsStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('redeem.searched', false)
            ->where('redeem.reward', null)
            ->where('redeem.can_redeem', false));
});

# The dialog offers no handover button without the right, but the record is still readable.
it('shows a reward without the power to hand it over', function () {
    $campaign = campaignHolding(['Audit' => 5]);
    $result = app(ShuffleRewardService::class)->claim(sessionOn($campaign));

    $this->actingAs(rewardsStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index', ['redeem' => strtolower($result->code)]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('redeem.reward.code', $result->code)
            ->where('redeem.can_redeem', false));
});

it('refuses a second redemption of the same reward', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsRedeem]);
    $campaign = campaignHolding(['Audit' => 5]);
    $result = app(ShuffleRewardService::class)->claim(sessionOn($campaign));

    $this->actingAs($actor)->post(route('admin.rewards.redeem.store'), ['code' => $result->code]);

    $this->actingAs($actor)
        ->from(route('admin.rewards.winners.index'))
        ->post(route('admin.rewards.redeem.store'), ['code' => $result->code])
        ->assertForbidden();

    expect($result->refresh()->redemption()->count())->toBe(1);
});

# =========================================================================
# Purchases
# =========================================================================

it('records a purchase and says whether it earned a turn', function () {
    $actor = rewardsStaff([
        Permission::PurchasesViewAny,
        Permission::PurchasesCreate,
        Permission::RewardsShuffle,
    ]);
    campaignHolding(['Audit' => 5], minimum: 100_000);

    $customer = Customer::factory()->create();

    $this->actingAs($actor)
        ->post(route('admin.purchases.store'), [
            'customer_id' => $customer->id,
            'amount' => '185000.00',
            'status' => PurchaseStatus::Completed->value,
            'purchased_at' => now()->subHour()->toDateTimeString(),
        ])
        ->assertRedirect(route('admin.purchases.index'));

    $this->actingAs($actor)
        ->get(route('admin.purchases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('purchases.data.0.amount', '185,000.00')
            ->where('purchases.data.0.refusal', null)
            ->where('purchases.data.0.shuffle_id', null));
});

it('refuses a sale dated in the future', function () {
    $actor = rewardsStaff([Permission::PurchasesViewAny, Permission::PurchasesCreate]);

    $this->actingAs($actor)
        ->post(route('admin.purchases.store'), [
            'customer_id' => Customer::factory()->create()->id,
            'amount' => '1000.00',
            'status' => PurchaseStatus::Completed->value,
            'purchased_at' => now()->addWeek()->toDateTimeString(),
        ])
        ->assertSessionHasErrors('purchased_at');
});

it('refuses a purchase filed against another customer visit', function () {
    $actor = rewardsStaff([Permission::PurchasesViewAny, Permission::PurchasesCreate]);

    $visit = Visit::factory()->create();

    $this->actingAs($actor)
        ->post(route('admin.purchases.store'), [
            'customer_id' => Customer::factory()->create()->id,
            'visit_id' => $visit->id,
            'amount' => '1000.00',
            'status' => PurchaseStatus::Completed->value,
            'purchased_at' => now()->subDay()->toDateTimeString(),
        ])
        ->assertSessionHasErrors('visit_id');
});
