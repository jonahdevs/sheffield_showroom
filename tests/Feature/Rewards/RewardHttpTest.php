<?php

use App\Enums\CampaignStatus;
use App\Enums\Permission;
use App\Enums\PurchaseStatus;
use App\Enums\RewardResultStatus;
use App\Enums\RewardType;
use App\Enums\ShuffleSessionStatus;
use App\Models\CampaignReward;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\RewardCampaign;
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
 * file: those are global functions, and Pest gives no guarantee about which
 * file declares one first.
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

// -----------------------------------------------------------------------------
// Who may reach what
// -----------------------------------------------------------------------------

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

// -----------------------------------------------------------------------------
// Building a campaign
// -----------------------------------------------------------------------------

it('creates a campaign with its drawer in one go', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'August showroom rewards',
            'max_shuffles_per_customer' => 1,
            'minimum_purchase_amount' => '100000.00',
            'rewards' => [
                ['name' => '10% discount', 'type' => RewardType::Discount->value, 'quantity' => 20, 'value' => '10.00', 'value_unit' => 'percentage'],
                ['name' => 'Free installation', 'type' => RewardType::Installation->value, 'quantity' => 15],
            ],
        ])
        ->assertSessionHasNoErrors();

    $campaign = RewardCampaign::query()->sole();

    expect($campaign->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->rewards()->count())->toBe(2)
        /* Nothing is loaded until it is published. */
        ->and($campaign->poolEntries()->count())->toBe(0);
});

/**
 * The form asks for a day, and a day taken literally is its midnight - the
 * start of it. A campaign set to end on the 28th would otherwise stop the
 * moment the 28th began, and the showroom would spend that day turning people
 * away from a promotion still on its own poster.
 */
it('runs an end date to the close of its day', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'Ends on the 28th',
            'max_shuffles_per_customer' => 1,
            'starts_at' => '2026-09-01',
            'ends_at' => '2026-09-28',
            'rewards' => [
                ['name' => 'Audit', 'type' => RewardType::KitchenAudit->value, 'quantity' => 5],
            ],
        ])
        ->assertSessionHasNoErrors();

    $campaign = RewardCampaign::query()->sole();

    expect($campaign->starts_at->toDateTimeString())->toBe('2026-09-01 00:00:00')
        ->and($campaign->ends_at->toDateTimeString())->toBe('2026-09-28 23:59:59')
        /* Which is the point of it: the last day is still a running day. */
        ->and($campaign->ends_at->isAfter(CarbonImmutable::parse('2026-09-28 17:00')))
        ->toBeTrue();
});

/** A caller that named a time meant it, and keeps it. */
it('leaves an end date alone when it already carries a time', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'Ends at noon',
            'max_shuffles_per_customer' => 1,
            'ends_at' => '2026-09-28 12:00:00',
            'rewards' => [
                ['name' => 'Audit', 'type' => RewardType::KitchenAudit->value, 'quantity' => 5],
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

it('refuses a figure with nothing saying how to read it', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsCreate]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.store'), [
            'name' => 'Ambiguous',
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                ['name' => 'Ten of something', 'type' => RewardType::Discount->value, 'quantity' => 5, 'value' => '10.00'],
            ],
        ])
        ->assertSessionHasErrors('rewards.0.value_unit');
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

/**
 * A published campaign's quantities are inventory. A stale form is dropped
 * rather than refused - the same way the profile screen drops an email nobody
 * may change.
 */
it('ignores reward changes posted at a published campaign', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsUpdate]);
    $campaign = campaignHolding(['Audit' => 10]);

    $this->actingAs($actor)
        ->patch(route('admin.rewards.update', $campaign), [
            'name' => 'Renamed',
            'max_shuffles_per_customer' => 1,
            'rewards' => [
                ['name' => 'Sneaky extra', 'type' => RewardType::Discount->value, 'quantity' => 500],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect($campaign->refresh()->name)->toBe('Renamed')
        ->and($campaign->rewards()->count())->toBe(1)
        ->and($campaign->rewards()->sole()->name)->toBe('Audit')
        ->and($campaign->poolEntries()->count())->toBe(10);
});

it('will not delete a campaign that has a pool', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsCampaignsDelete]);
    $campaign = campaignHolding(['Audit' => 5]);

    $this->actingAs($actor)
        ->delete(route('admin.rewards.destroy', $campaign))
        ->assertForbidden();

    expect(RewardCampaign::query()->whereKey($campaign->id)->exists())->toBeTrue();
});

// -----------------------------------------------------------------------------
// Giving a turn, and running it
// -----------------------------------------------------------------------------

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
            /* The QR screen is the one authenticated page that carries a
               token, because drawing the code is the whole job. */
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

// -----------------------------------------------------------------------------
// The public page
// -----------------------------------------------------------------------------

it('opens the customer page with nothing but a token', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 5]);
    $session = sessionOn($campaign);

    $this->get(route('rewards.shuffle.show', $session->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('rewards/Shuffle')
            ->where('state', 'ready')
            ->where('campaign.name', $campaign->name)
            /* The customer's page never carries inventory. */
            ->missing('campaign.available')
            ->missing('campaign.loaded')
            ->has('cards', 1)
            /* Nothing about who this is, and nothing about how much is left. */
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
            /* The reveal never names the person holding the phone. */
            ->where('reward.customer_name', null));

    expect($session->refresh()->status)->toBe(ShuffleSessionStatus::Shuffled)
        ->and($campaign->availableCount())->toBe(4);
});

/**
 * A double tap, which is what actually happens on a phone at a counter. The
 * second post must not win a second reward.
 */
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

// -----------------------------------------------------------------------------
// Redeeming
// -----------------------------------------------------------------------------

it('finds a reward by its code and hands it over', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsRedeem]);
    $campaign = campaignHolding(['Audit' => 5]);
    $result = app(ShuffleRewardService::class)->claim(sessionOn($campaign));

    $this->actingAs($actor)
        ->get(route('admin.rewards.redeem.index', ['code' => $result->code]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/rewards/Redeem')
            ->where('reward.code', $result->code)
            /* The desk does need to know who won it. */
            ->where('can.redeem', true));

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
        ->from(route('admin.rewards.redeem.index'))
        ->post(route('admin.rewards.redeem.store'), ['code' => 'SHF-ZZZZZZ'])
        ->assertSessionHasErrors('code');
});

it('refuses a second redemption of the same reward', function () {
    $actor = rewardsStaff([Permission::RewardsView, Permission::RewardsRedeem]);
    $campaign = campaignHolding(['Audit' => 5]);
    $result = app(ShuffleRewardService::class)->claim(sessionOn($campaign));

    $this->actingAs($actor)->post(route('admin.rewards.redeem.store'), ['code' => $result->code]);

    $this->actingAs($actor)
        ->from(route('admin.rewards.redeem.index'))
        ->post(route('admin.rewards.redeem.store'), ['code' => $result->code])
        ->assertForbidden();

    expect($result->refresh()->redemption()->count())->toBe(1);
});

// -----------------------------------------------------------------------------
// Purchases
// -----------------------------------------------------------------------------

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
            /* Nothing standing between this sale and a shuffle. */
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
