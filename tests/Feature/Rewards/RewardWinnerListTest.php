<?php

use App\Enums\CustomerType;
use App\Enums\Permission;
use App\Enums\RewardResultStatus;
use App\Enums\RewardType;
use App\Models\CampaignReward;
use App\Models\Customer;
use App\Models\RewardCampaign;
use App\Models\RewardPoolEntry;
use App\Models\RewardRedemption;
use App\Models\Role;
use App\Models\ShuffleResult;
use App\Models\ShuffleSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

/**
 * A user holding exactly the permissions named, through a role of their own.
 *
 * Self-contained rather than calling the equivalent in `RewardHttpTest`: those
 * are global functions, and Pest gives no guarantee about which file declares
 * one first.
 *
 * @param  array<int, Permission>  $permissions
 */
function winnerListStaff(array $permissions): User
{
    foreach (Permission::values() as $name) {
        SpatiePermission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'winners-tester-'.Str::random(8),
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

/**
 * One won reward, with everything above it wired up coherently.
 *
 * Built by hand rather than through the result factory's own defaults because
 * every assertion below is about something further up the chain - the
 * customer's name, the campaign's, the type of the reward - and a factory that
 * invents a fresh campaign per call cannot be asserted against.
 */
function wonReward(
    RewardCampaign $campaign,
    CampaignReward $reward,
    Customer $customer,
    string $code,
    ?CarbonImmutable $wonAt = null,
    RewardResultStatus $status = RewardResultStatus::Unredeemed,
): ShuffleResult {
    $entry = RewardPoolEntry::factory()->claimed()->create([
        'campaign_id' => $campaign->id,
        'campaign_reward_id' => $reward->id,
    ]);

    $session = ShuffleSession::factory()->shuffled()->create([
        'campaign_id' => $campaign->id,
        'customer_id' => $customer->id,
    ]);

    return ShuffleResult::factory()->create([
        'shuffle_session_id' => $session->id,
        'reward_pool_entry_id' => $entry->id,
        'code' => $code,
        'won_at' => $wonAt ?? CarbonImmutable::now(),
        'status' => $status,
    ]);
}

// -----------------------------------------------------------------------------
// Who may reach it
// -----------------------------------------------------------------------------

it('keeps the won rewards behind rewards.view', function () {
    $this->actingAs(winnerListStaff([Permission::PurchasesViewAny]))
        ->get(route('admin.rewards.winners.index'))
        ->assertForbidden();
});

it('opens for anybody with rewards.view, without the power to redeem', function () {
    $this->actingAs(winnerListStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index'))
        ->assertOk();
});

// -----------------------------------------------------------------------------
// What a row says
// -----------------------------------------------------------------------------

it('names the customer, the campaign and the reward on every row', function () {
    $campaign = RewardCampaign::factory()->active()->create(['name' => 'Clearance Sale']);
    $reward = CampaignReward::factory()->discount(10)->create(['campaign_id' => $campaign->id]);
    $customer = Customer::factory()->create([
        'name' => 'Amina Otieno',
        'type' => CustomerType::Individual,
    ]);

    wonReward($campaign, $reward, $customer, 'SHF-AB12CD');

    $this->actingAs(winnerListStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/rewards/Winners')
            ->has('rewards.data', 1)
            ->where('rewards.data.0.code', 'SHF-AB12CD')
            ->where('rewards.data.0.customer_name', 'Amina Otieno')
            ->where('rewards.data.0.campaign_name', 'Clearance Sale')
            ->where('rewards.data.0.reward_name', '10% discount')
            ->where('rewards.data.0.type', RewardType::Discount->value)
            ->where('rewards.data.0.value', '10%')
            ->where('rewards.data.0.status', RewardResultStatus::Unredeemed->value));
});

it('says who handed a reward over and when', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);
    $customer = Customer::factory()->create();
    $staff = User::factory()->create(['name' => 'Rachael']);

    $result = wonReward(
        $campaign,
        $reward,
        $customer,
        'SHF-DONE01',
        status: RewardResultStatus::Redeemed,
    );

    RewardRedemption::factory()->create([
        'shuffle_result_id' => $result->id,
        'redeemed_by' => $staff->id,
        'redeemed_at' => CarbonImmutable::parse('2026-08-20 11:00'),
    ]);

    $this->actingAs(winnerListStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index'))
        ->assertInertia(fn ($page) => $page
            ->where('rewards.data.0.redeemed_by', 'Rachael')
            ->where('rewards.data.0.redeemed_on', '20 Aug 2026'));
});

// -----------------------------------------------------------------------------
// Finding one
// -----------------------------------------------------------------------------

it('finds a reward by its code and by the customer who won it', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);

    $amina = Customer::factory()->create(['name' => 'Amina Otieno']);
    $brian = Customer::factory()->create(['name' => 'Brian Kimani']);

    wonReward($campaign, $reward, $amina, 'SHF-AAA111');
    wonReward($campaign, $reward, $brian, 'SHF-BBB222');

    $staff = winnerListStaff([Permission::RewardsView]);

    $this->actingAs($staff)
        ->get(route('admin.rewards.winners.index', ['search' => 'SHF-AAA111']))
        ->assertInertia(fn ($page) => $page
            ->has('rewards.data', 1)
            ->where('rewards.data.0.customer_name', 'Amina Otieno'));

    $this->actingAs($staff)
        ->get(route('admin.rewards.winners.index', ['search' => 'Brian']))
        ->assertInertia(fn ($page) => $page
            ->has('rewards.data', 1)
            ->where('rewards.data.0.code', 'SHF-BBB222'));
});

it('narrows by status, by reward type and by campaign', function () {
    $clearance = RewardCampaign::factory()->active()->create(['name' => 'Clearance Sale']);
    $festive = RewardCampaign::factory()->active()->create(['name' => 'Festive Draw']);

    $discount = CampaignReward::factory()->discount(10)->create(['campaign_id' => $clearance->id]);
    $audit = CampaignReward::factory()->create([
        'campaign_id' => $clearance->id,
        'type' => RewardType::KitchenAudit,
    ]);
    $festiveReward = CampaignReward::factory()->create(['campaign_id' => $festive->id]);

    $customer = Customer::factory()->create();

    wonReward($clearance, $discount, $customer, 'SHF-DIS001');
    wonReward($clearance, $audit, $customer, 'SHF-AUD002', status: RewardResultStatus::Redeemed);
    wonReward($festive, $festiveReward, $customer, 'SHF-FES003');

    $staff = winnerListStaff([Permission::RewardsView]);

    $this->actingAs($staff)
        ->get(route('admin.rewards.winners.index', ['status' => RewardResultStatus::Redeemed->value]))
        ->assertInertia(fn ($page) => $page
            ->has('rewards.data', 1)
            ->where('rewards.data.0.code', 'SHF-AUD002'));

    $this->actingAs($staff)
        ->get(route('admin.rewards.winners.index', ['type' => RewardType::Discount->value]))
        ->assertInertia(fn ($page) => $page
            ->has('rewards.data', 1)
            ->where('rewards.data.0.code', 'SHF-DIS001'));

    $this->actingAs($staff)
        ->get(route('admin.rewards.winners.index', ['campaign' => $festive->id]))
        ->assertInertia(fn ($page) => $page
            ->has('rewards.data', 1)
            ->where('rewards.data.0.code', 'SHF-FES003'));
});

// -----------------------------------------------------------------------------
// The window at the top of the page
// -----------------------------------------------------------------------------

it('reads the list and the figures under the same date window', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);
    $customer = Customer::factory()->create();

    wonReward($campaign, $reward, $customer, 'SHF-OLD001', CarbonImmutable::parse('2026-01-15 10:00'));
    wonReward($campaign, $reward, $customer, 'SHF-NEW002', CarbonImmutable::parse('2026-02-10 10:00'));

    $this->actingAs(winnerListStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index', [
            'from' => '2026-02-01',
            'to' => '2026-02-28',
        ]))
        ->assertInertia(fn ($page) => $page
            ->has('rewards.data', 1)
            ->where('rewards.data.0.code', 'SHF-NEW002')
            /* The tiles read the same window as the rows: one reward won in
               February, not the two in the table's whole history. */
            ->where('stats.0.key', 'won')
            ->where('stats.0.value', 1)
            ->where('date_label', '2026-02-01 to 2026-02-28'));
});

it('counts the collected and the outstanding against the window they were won in', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);
    $customer = Customer::factory()->create();

    $wonAt = CarbonImmutable::parse('2026-02-10 10:00');

    wonReward($campaign, $reward, $customer, 'SHF-GOT001', $wonAt, RewardResultStatus::Redeemed);
    wonReward($campaign, $reward, $customer, 'SHF-OPEN02', $wonAt);
    wonReward($campaign, $reward, $customer, 'SHF-OPEN03', $wonAt);

    $this->actingAs(winnerListStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index', ['from' => '2026-02-01', 'to' => '2026-02-28']))
        ->assertInertia(fn ($page) => $page
            ->where('stats.0.value', 3)
            ->where('stats.1.key', 'redeemed')
            ->where('stats.1.value', 1)
            ->where('stats.2.key', 'outstanding')
            ->where('stats.2.value', 2));
});

it('ignores a status filter when counting, so the figures stay a rate', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);
    $customer = Customer::factory()->create();

    wonReward($campaign, $reward, $customer, 'SHF-GOT001', status: RewardResultStatus::Redeemed);
    wonReward($campaign, $reward, $customer, 'SHF-OPEN02');

    $this->actingAs(winnerListStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index', ['status' => RewardResultStatus::Redeemed->value]))
        ->assertInertia(fn ($page) => $page
            /* One row, because that is what was asked for - but two won, or
               "collected: 1 of 1" would read as a perfect record. */
            ->has('rewards.data', 1)
            ->where('stats.0.value', 2)
            ->where('stats.1.value', 1));
});

it('answers a mangled query string with the list rather than an error', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);
    wonReward($campaign, $reward, Customer::factory()->create(), 'SHF-ANY001');

    $this->actingAs(winnerListStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index', [
            'from' => 'yesterday',
            'status' => 'made-up',
            'campaign' => 'not-a-number',
            'range' => 'last_century',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rewards.data', 1)
            ->where('filters.from', '')
            ->where('filters.status', '')
            ->where('filters.campaign', '')
            ->where('filters.range', ''));
});

// -----------------------------------------------------------------------------
// What it must not carry
// -----------------------------------------------------------------------------

it('never puts a session token on the page', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);
    $result = wonReward($campaign, $reward, Customer::factory()->create(), 'SHF-TOK001');

    $token = $result->session->getAttribute('token');

    expect($token)->not->toBe('');

    $this->actingAs(winnerListStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index'))
        ->assertOk()
        ->assertDontSee($token, escape: false);
});

it('offers only campaigns that have handed something out', function () {
    $played = RewardCampaign::factory()->active()->create(['name' => 'Clearance Sale']);
    $reward = CampaignReward::factory()->create(['campaign_id' => $played->id]);
    RewardCampaign::factory()->create(['name' => 'Never Published']);

    wonReward($played, $reward, Customer::factory()->create(), 'SHF-ONE001');

    $this->actingAs(winnerListStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index'))
        ->assertInertia(fn ($page) => $page
            ->has('campaigns', 1)
            ->where('campaigns.0.label', 'Clearance Sale'));
});
