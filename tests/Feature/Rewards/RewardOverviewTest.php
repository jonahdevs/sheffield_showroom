<?php

use App\Enums\CampaignStatus;
use App\Enums\Permission;
use App\Enums\RewardResultStatus;
use App\Models\CampaignReward;
use App\Models\Customer;
use App\Models\RewardCampaign;
use App\Models\RewardPoolEntry;
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
 * Self-contained rather than calling the equivalent in `RewardHttpTest`: these
 * helpers are global functions, Pest gives no guarantee which file declares
 * one first, and two files declaring the same name is a fatal error.
 *
 * @param  array<int, Permission>  $permissions
 */
function overviewStaff(array $permissions): User
{
    foreach (Permission::values() as $name) {
        SpatiePermission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'overview-tester-'.Str::random(8),
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

/**
 * Built by hand rather than left to the result factory's defaults, which
 * invent a campaign of their own - and every figure this page reports is
 * counted across one campaign.
 */
function overviewWin(
    RewardCampaign $campaign,
    CampaignReward $reward,
    CarbonImmutable $wonAt,
    RewardResultStatus $status = RewardResultStatus::Unredeemed,
    ?CarbonImmutable $expiresAt = null,
): ShuffleResult {
    $entry = RewardPoolEntry::factory()->claimed()->create([
        'campaign_id' => $campaign->id,
        'campaign_reward_id' => $reward->id,
    ]);

    $session = ShuffleSession::factory()->shuffled()->create([
        'campaign_id' => $campaign->id,
        'customer_id' => Customer::factory(),
        'created_at' => $wonAt,
        'updated_at' => $wonAt,
    ]);

    return ShuffleResult::factory()->create([
        'shuffle_session_id' => $session->id,
        'reward_pool_entry_id' => $entry->id,
        'won_at' => $wonAt,
        'expires_at' => $expiresAt ?? $wonAt->addDays(30),
        'status' => $status,
    ]);
}

# =========================================================================
# Who may reach it
# =========================================================================

it('keeps the overview behind rewards.view', function () {
    $this->actingAs(overviewStaff([Permission::PurchasesViewAny]))
        ->get(route('admin.rewards.overview.index'))
        ->assertForbidden();
});

it('opens for anybody with rewards.view, without the power to run a campaign', function () {
    $this->actingAs(overviewStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.overview.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/rewards/Overview'));
});

# =========================================================================
# The header
# =========================================================================

it('reads as a calm page when no promotion is running', function () {
    RewardCampaign::factory()->create(['status' => CampaignStatus::Draft]);

    $this->actingAs(overviewStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.overview.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('headline', null)
            ->where('drawer', [])
            ->has('stats', 4)
            ->has('wins', 7));
});

it('leads with the campaign that is actually running', function () {
    $campaign = RewardCampaign::factory()->active()->create(['name' => 'Clearance Sale']);

    $this->actingAs(overviewStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.overview.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('headline.campaign.id', $campaign->id)
            ->where('headline.campaign.name', 'Clearance Sale')
            ->where('headline.dormant_reason', null)
            ->where('headline.days_remaining', 6));
});

it('says why a campaign marked active is handing nothing out', function () {
    $campaign = RewardCampaign::factory()->ended()->create();

    $this->actingAs(overviewStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.overview.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('headline.campaign.id', $campaign->id)
            ->where('headline.campaign.status', CampaignStatus::Active->value)
            ->where('headline.campaign.is_running', false)
            ->where('headline.dormant_reason', 'Past its end date')
            ->where('headline.days_remaining', null));
});

# =========================================================================
# The figures
# =========================================================================

it('counts the turns, the wins, the redemptions and what is still owed', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);
    $now = CarbonImmutable::now();

    overviewWin($campaign, $reward, $now->subDays(2), RewardResultStatus::Redeemed);
    overviewWin($campaign, $reward, $now->subDays(3));
    overviewWin($campaign, $reward, $now->subDays(4), RewardResultStatus::Cancelled);

    # A turn handed out and never taken: counts towards the first tile and
    # towards nothing else.
    ShuffleSession::factory()->create([
        'campaign_id' => $campaign->id,
        'created_at' => $now->subDay(),
    ]);

    # Outside the window, so none of the four figures may see it.
    overviewWin($campaign, $reward, $now->subDays(40));

    $this->actingAs(overviewStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.overview.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.0.key', 'turns')
            ->where('stats.0.value', 4)
            ->where('stats.1.key', 'won')
            ->where('stats.1.value', 3)
            ->where('stats.2.key', 'redeemed')
            ->where('stats.2.value', 1)
            ->where('stats.3.key', 'unclaimed')
            ->where('stats.3.value', 1));
});

# Between the deadline and the nightly sweep a row is still marked
# unredeemed; the date decides, so it must not count as outstanding.
it('does not count an expired but unswept reward as still claimable', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);
    $now = CarbonImmutable::now();

    overviewWin(
        $campaign,
        $reward,
        $now->subDays(3),
        RewardResultStatus::Unredeemed,
        expiresAt: $now->subDay(),
    );

    $this->actingAs(overviewStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.overview.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.1.value', 1)
            ->where('stats.3.key', 'unclaimed')
            ->where('stats.3.value', 0)
            ->where('outcomes.0.value', 'lapsed')
            ->where('outcomes.0.count', 1));
});

it('has no collection rate at all where nothing has been won', function () {
    RewardCampaign::factory()->active()->create();

    $this->actingAs(overviewStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.overview.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('collection_rate', null)
            ->where('outcomes', []));
});

it('reads the collection rate off the wins in the window', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);
    $now = CarbonImmutable::now();

    overviewWin($campaign, $reward, $now->subDay(), RewardResultStatus::Redeemed);
    overviewWin($campaign, $reward, $now->subDay(), RewardResultStatus::Redeemed);
    overviewWin($campaign, $reward, $now->subDay());
    overviewWin($campaign, $reward, $now->subDay());

    $this->actingAs(overviewStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.overview.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('collection_rate', 50));
});

# =========================================================================
# The history
# =========================================================================

it('lists the promotions that are over, with what each handed out', function () {
    $running = RewardCampaign::factory()->active()->create(['name' => 'This one']);

    $past = RewardCampaign::factory()->create([
        'name' => 'Last summer',
        'status' => CampaignStatus::Completed,
        'starts_at' => CarbonImmutable::now()->subMonths(4),
        'ends_at' => CarbonImmutable::now()->subMonths(3),
    ]);

    $reward = CampaignReward::factory()->create(['campaign_id' => $past->id]);

    RewardPoolEntry::factory()->count(3)->create([
        'campaign_id' => $past->id,
        'campaign_reward_id' => $reward->id,
    ]);

    overviewWin($past, $reward, CarbonImmutable::now()->subMonths(3), RewardResultStatus::Redeemed);
    overviewWin($past, $reward, CarbonImmutable::now()->subMonths(3));

    $this->actingAs(overviewStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.overview.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('past', 1)
            ->where('past.0.id', $past->id)
            ->where('past.0.name', 'Last summer')
            # Loaded is everything put in, not what is left: three still in
            # the pool plus the two the wins above claimed.
            ->where('past.0.loaded', 5)
            ->where('past.0.won', 2)
            ->where('past.0.redeemed', 1)
            ->where('past.0.collection_rate', 50)
            ->where('headline.campaign.id', $running->id));
});
