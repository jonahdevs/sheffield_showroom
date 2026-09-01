<?php

use App\Enums\CampaignStatus;
use App\Enums\PoolEntryStatus;
use App\Enums\RewardResultStatus;
use App\Enums\ShuffleSessionStatus;
use App\Exceptions\CampaignStateException;
use App\Exceptions\ShuffleUnavailableException;
use App\Models\CampaignReward;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\RewardCampaign;
use App\Models\ShuffleResult;
use App\Models\ShuffleSession;
use App\Models\User;
use App\Services\Rewards\CampaignService;
use App\Services\Rewards\RewardEligibilityService;
use App\Services\Rewards\RewardPoolService;
use App\Services\Rewards\RewardRedemptionService;
use App\Services\Rewards\ShuffleRewardService;
use App\Services\Rewards\ShuffleSessionService;
use Carbon\CarbonImmutable;

# =========================================================================
# Publishing a campaign
# =========================================================================

it('writes exactly the pool the quantities describe', function () {
    $campaign = campaignHolding(['Discount' => 20, 'Audit' => 25, 'Installation' => 15]);

    expect($campaign->poolEntries()->count())->toBe(60)
        ->and($campaign->availableCount())->toBe(60)
        ->and($campaign->status)->toBe(CampaignStatus::Active);

    $inventory = app(RewardPoolService::class)->inventory($campaign);

    expect(array_column($inventory, 'available'))->toBe([20, 25, 15]);
});

it('refuses to publish a campaign with nothing in it', function () {
    $campaign = RewardCampaign::factory()->create(['status' => CampaignStatus::Draft]);

    expect(fn () => app(CampaignService::class)->publish($campaign))
        ->toThrow(CampaignStateException::class);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Draft);
});

it('refuses to publish the same campaign twice', function () {
    $campaign = campaignHolding(['Audit' => 5]);

    expect(fn () => app(CampaignService::class)->publish($campaign))
        ->toThrow(CampaignStateException::class);

    expect($campaign->poolEntries()->count())->toBe(5);
});

it('holds a campaign back until its start date', function () {
    $campaign = RewardCampaign::factory()->create([
        'status' => CampaignStatus::Draft,
        'starts_at' => CarbonImmutable::now()->addWeek(),
    ]);
    CampaignReward::factory()->quantity(5)->create(['campaign_id' => $campaign->id]);

    app(CampaignService::class)->publish($campaign);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Scheduled)
        # Loaded all the same: the drawer fills before the doors open.
        ->and($campaign->poolEntries()->count())->toBe(5);
});

it('refuses to run two campaigns at once', function () {
    campaignHolding(['Audit' => 5]);

    $second = RewardCampaign::factory()->create(['status' => CampaignStatus::Draft]);
    CampaignReward::factory()->quantity(5)->create(['campaign_id' => $second->id]);

    expect(fn () => app(CampaignService::class)->publish($second))
        ->toThrow(CampaignStateException::class);

    expect($second->refresh()->status)->toBe(CampaignStatus::Draft);
});

it('lets a second campaign start once the first is paused', function () {
    $first = campaignHolding(['Audit' => 5]);
    app(CampaignService::class)->pause($first);

    $second = RewardCampaign::factory()->create(['status' => CampaignStatus::Draft]);
    CampaignReward::factory()->quantity(5)->create(['campaign_id' => $second->id]);

    app(CampaignService::class)->publish($second);

    expect($second->refresh()->status)->toBe(CampaignStatus::Active);
});

it('will not reopen a campaign that is over', function () {
    $campaign = campaignHolding(['Audit' => 5]);
    app(CampaignService::class)->complete($campaign);

    expect(fn () => app(CampaignService::class)->activate($campaign->refresh()))
        ->toThrow(CampaignStateException::class);
});

# Void keeps `loaded = available + claimed + void` reconciling.
it('voids only the units nobody has won', function () {
    $campaign = campaignHolding(['Audit' => 10]);
    $reward = $campaign->rewards()->sole();

    app(ShuffleRewardService::class)->claim(sessionOn($campaign));

    $voided = app(RewardPoolService::class)->void($reward, 3);

    expect($voided)->toBe(3)
        ->and($campaign->availableCount())->toBe(6)
        ->and($campaign->poolEntries()->where('status', PoolEntryStatus::Claimed)->count())->toBe(1)
        ->and($campaign->poolEntries()->count())->toBe(10);
});

# =========================================================================
# Earning a turn
# =========================================================================

it('gives a qualifying purchase exactly one turn', function () {
    $campaign = campaignHolding(['Audit' => 5], minimum: 100_000);
    $purchase = Purchase::factory()->worth(185_000)->create();

    $session = app(ShuffleSessionService::class)->mintFor($purchase, User::factory()->create());

    expect($session)->not->toBeNull()
        ->and($session->purchase_id)->toBe($purchase->id)
        ->and($session->campaign_id)->toBe($campaign->id)
        ->and($session->customer_id)->toBe($purchase->customer_id)
        ->and($session->status)->toBe(ShuffleSessionStatus::Pending)
        ->and(strlen($session->token))->toBe(64);
});

it('turns down a purchase under the threshold, and says by how much', function () {
    campaignHolding(['Audit' => 5], minimum: 100_000);
    $purchase = Purchase::factory()->worth(99_999)->create();

    $refusal = app(RewardEligibilityService::class)->refusalFor($purchase);

    expect($refusal)->toContain('100,000.00')
        ->and(app(ShuffleSessionService::class)->mintFor($purchase))->toBeNull();
});

it('turns down a sale that has not completed', function () {
    campaignHolding(['Audit' => 5]);
    $purchase = Purchase::factory()->pending()->create();

    expect(app(RewardEligibilityService::class)->refusalFor($purchase))
        ->toContain('completed purchase')
        ->and(app(ShuffleSessionService::class)->mintFor($purchase))->toBeNull();
});

it('turns down every purchase when no campaign is running', function () {
    $purchase = Purchase::factory()->create();

    expect(app(RewardEligibilityService::class)->refusalFor($purchase))
        ->toContain('No reward campaign')
        ->and(app(ShuffleSessionService::class)->mintFor($purchase))->toBeNull();
});

# Minted twice on purpose: two staff pressing the button together must get
# the same turn back, not a second one and not an error.
it('gives one sale one turn however many times it is asked', function () {
    campaignHolding(['Audit' => 5]);
    $purchase = Purchase::factory()->create();

    $service = app(ShuffleSessionService::class);

    $first = $service->mintFor($purchase);
    $second = $service->mintFor($purchase->refresh());

    expect($second)->toBeNull()
        ->and(ShuffleSession::query()->where('purchase_id', $purchase->id)->count())->toBe(1)
        ->and($first->id)->not->toBeNull();
});

it('stops a customer past the campaign ceiling', function () {
    $campaign = campaignHolding(['Audit' => 20]);
    $campaign->forceFill(['max_shuffles_per_customer' => 2])->save();

    $customer = Customer::factory()->create();
    $service = app(ShuffleSessionService::class);

    foreach (range(1, 2) as $ignored) {
        $purchase = Purchase::factory()->create(['customer_id' => $customer->id]);
        expect($service->mintFor($purchase))->not->toBeNull();
    }

    $third = Purchase::factory()->create(['customer_id' => $customer->id]);

    expect(app(RewardEligibilityService::class)->refusalFor($third))->toContain('2 of 2')
        ->and($service->mintFor($third))->toBeNull();
});

# =========================================================================
# The public token
# =========================================================================

it('finds a live turn by its token and refuses everything else', function () {
    $campaign = campaignHolding(['Audit' => 5]);
    $service = app(ShuffleSessionService::class);

    $live = sessionOn($campaign);

    expect($service->forToken($live->token)->id)->toBe($live->id);

    expect(fn () => $service->forToken('not-a-real-token'))
        ->toThrow(ShuffleUnavailableException::class);

    $used = ShuffleSession::factory()->shuffled()->create(['campaign_id' => $campaign->id]);
    expect(fn () => $service->forToken($used->token))->toThrow(ShuffleUnavailableException::class);

    $lapsed = ShuffleSession::factory()->expired()->create(['campaign_id' => $campaign->id]);
    expect(fn () => $service->forToken($lapsed->token))->toThrow(ShuffleUnavailableException::class);
});

it('cancels a turn before it is used but not after', function () {
    $campaign = campaignHolding(['Audit' => 5]);
    $service = app(ShuffleSessionService::class);

    $unused = sessionOn($campaign);
    $service->cancel($unused);

    expect($unused->refresh()->status)->toBe(ShuffleSessionStatus::Cancelled);

    $used = sessionOn($campaign);
    app(ShuffleRewardService::class)->claim($used);

    expect(fn () => $service->cancel($used->refresh()))
        ->toThrow(ShuffleUnavailableException::class);
});

# =========================================================================
# Handing the reward over
# =========================================================================

it('redeems a reward by the code the customer quotes', function () {
    $campaign = campaignHolding(['Audit' => 5]);
    $result = app(ShuffleRewardService::class)->claim(sessionOn($campaign));
    $staff = User::factory()->create();

    $service = app(RewardRedemptionService::class);

    $found = $service->find('  '.strtolower($result->code).' ');

    expect($found?->id)->toBe($result->id);

    $redemption = $service->redeem($found, $staff, 'Fitted on site.');

    expect($redemption->redeemed_by)->toBe($staff->id)
        ->and($redemption->notes)->toBe('Fitted on site.')
        ->and($result->refresh()->status)->toBe(RewardResultStatus::Redeemed);
});

it('refuses to redeem the same reward twice', function () {
    $campaign = campaignHolding(['Audit' => 5]);
    $result = app(ShuffleRewardService::class)->claim(sessionOn($campaign));

    $service = app(RewardRedemptionService::class);
    $service->redeem($result);

    expect(fn () => $service->redeem($result->refresh()))
        ->toThrow(ShuffleUnavailableException::class);

    expect($result->refresh()->redemption()->count())->toBe(1);
});

# The unswept row: still `Unredeemed`, and refused on the date alone.
it('refuses to redeem a reward whose date has passed', function () {
    $result = ShuffleResult::factory()->lapsed()->create();

    expect($result->status)->toBe(RewardResultStatus::Unredeemed)
        ->and(fn () => app(RewardRedemptionService::class)->redeem($result))
        ->toThrow(ShuffleUnavailableException::class);
});

it('says nothing found for a code that is not one', function () {
    expect(app(RewardRedemptionService::class)->find('SHF-ZZZZZZ'))->toBeNull();
});

# =========================================================================
# The whole way through
# =========================================================================

it('carries one customer from purchase to redemption', function () {
    $campaign = campaignHolding([
        '10% discount' => 20,
        'Free drawing and layout' => 25,
        'Free kitchen audit' => 20,
        'One complimentary service' => 20,
        'Free installation' => 15,
    ], minimum: 100_000);

    expect($campaign->availableCount())->toBe(100);

    $customer = Customer::factory()->create();
    $purchase = Purchase::factory()->worth(185_000)->create(['customer_id' => $customer->id]);

    $session = app(ShuffleSessionService::class)->mintFor($purchase);
    $result = app(ShuffleRewardService::class)->claim($session);

    app(RewardRedemptionService::class)->redeem(
        $result,
        User::factory()->create(),
        at: CarbonImmutable::now()->addWeeks(3),
    );

    $result->refresh()->load('session.purchase', 'poolEntry.reward');

    expect($campaign->availableCount())->toBe(99)
        ->and($result->status)->toBe(RewardResultStatus::Redeemed)
        ->and($result->session->customer_id)->toBe($customer->id)
        ->and($result->session->purchase->id)->toBe($purchase->id)
        ->and($result->poolEntry->reward->campaign_id)->toBe($campaign->id);
});
