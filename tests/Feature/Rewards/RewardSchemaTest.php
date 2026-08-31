<?php

use App\Enums\CampaignStatus;
use App\Models\CampaignReward;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\RewardCampaign;
use App\Models\RewardPoolEntry;
use App\Models\ShuffleResult;
use App\Models\ShuffleSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * The constraints under the reward feature, tested against the database rather
 * than against the code that is supposed to respect them.
 *
 * The claiming transaction takes a row lock and should make all three of these
 * unreachable. These tests are here for the day somebody adds a second path to
 * these tables and gets the locking wrong: the database has to refuse, so that
 * the failure is an error rather than a customer holding a reward that was
 * already given away.
 */
it('refuses a second result against the same turn', function () {
    $result = ShuffleResult::factory()->create();

    expect(fn () => ShuffleResult::factory()->create([
        'shuffle_session_id' => $result->shuffle_session_id,
    ]))->toThrow(QueryException::class);
});

it('refuses to hand the same reward unit to two people', function () {
    $result = ShuffleResult::factory()->create();

    expect(fn () => ShuffleResult::factory()->create([
        'reward_pool_entry_id' => $result->reward_pool_entry_id,
    ]))->toThrow(QueryException::class);
});

/**
 * The rule the whole entitlement model rests on. Held by a unique index rather
 * than by the eligibility service, because a service can be raced by two staff
 * pressing the button at once and an index cannot.
 */
it('refuses a second turn against the same purchase', function () {
    $purchase = Purchase::factory()->create();

    ShuffleSession::factory()->create(['purchase_id' => $purchase->id]);

    expect(fn () => ShuffleSession::factory()->create([
        'purchase_id' => $purchase->id,
    ]))->toThrow(QueryException::class);
});

/**
 * The other half of that index: nulls repeat. A staff-run turn with no
 * purchase behind it has to stay possible, and several of them must not
 * collide with each other.
 */
it('allows any number of turns with no purchase behind them', function () {
    ShuffleSession::factory()->count(3)->create(['purchase_id' => null]);

    expect(ShuffleSession::query()->whereNull('purchase_id')->count())->toBe(3);
});

it('refuses a second redemption of one reward', function () {
    $result = ShuffleResult::factory()->redeemed()->create();

    $result->redemption()->create([
        'redeemed_by' => null,
        'redeemed_at' => now(),
    ]);

    expect(fn () => $result->redemption()->create([
        'redeemed_by' => null,
        'redeemed_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('will not drop a campaign that has given somebody a turn', function () {
    $session = ShuffleSession::factory()->create();

    expect(fn () => RewardCampaign::query()->whereKey($session->campaign_id)->delete())
        ->toThrow(QueryException::class);
});

// -----------------------------------------------------------------------------
// What the models say about state
// -----------------------------------------------------------------------------

it('only calls a campaign running when it is active and the calendar agrees', function (
    CampaignStatus $status,
    ?string $starts,
    ?string $ends,
    bool $expected,
) {
    $campaign = RewardCampaign::factory()->create([
        'status' => $status,
        'starts_at' => $starts === null ? null : CarbonImmutable::parse($starts),
        'ends_at' => $ends === null ? null : CarbonImmutable::parse($ends),
    ]);

    expect($campaign->isRunning())->toBe($expected);
})->with([
    'active, open at both ends' => [CampaignStatus::Active, null, null, true],
    'active and under way' => [CampaignStatus::Active, '-1 week', '+1 week', true],
    'active but not started' => [CampaignStatus::Active, '+1 day', '+1 month', false],
    'active but finished' => [CampaignStatus::Active, '-1 month', '-1 day', false],
    'paused mid-window' => [CampaignStatus::Paused, '-1 week', '+1 week', false],
    'still a draft' => [CampaignStatus::Draft, null, null, false],
]);

/**
 * Expiry is stamped when a reward is won, never recomputed. An administrator
 * lengthening `validity_days` afterwards must not move a deadline somebody was
 * already given.
 */
it('reads an expiry off the reward definition at the moment of winning', function () {
    $wonAt = CarbonImmutable::parse('2026-08-31 10:00:00');

    $lapsing = CampaignReward::factory()->create(['validity_days' => 30]);
    $forever = CampaignReward::factory()->neverExpiring()->create();

    expect($lapsing->expiryFrom($wonAt)->toDateTimeString())->toBe('2026-09-30 10:00:00')
        ->and($forever->expiryFrom($wonAt))->toBeNull();
});

it('counts what is left off the pool rather than off the quantity loaded', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->quantity(10)->create(['campaign_id' => $campaign->id]);

    RewardPoolEntry::factory()->count(6)->create([
        'campaign_id' => $campaign->id,
        'campaign_reward_id' => $reward->id,
    ]);
    RewardPoolEntry::factory()->count(3)->claimed()->create([
        'campaign_id' => $campaign->id,
        'campaign_reward_id' => $reward->id,
    ]);
    RewardPoolEntry::factory()->void()->create([
        'campaign_id' => $campaign->id,
        'campaign_reward_id' => $reward->id,
    ]);

    /* `quantity` is what was loaded and never falls; a void unit is off the
       table but still counted as loaded, which is what makes the reporting
       reconcile. */
    expect($reward->quantity)->toBe(10)
        ->and($reward->availableCount())->toBe(6)
        ->and($campaign->availableCount())->toBe(6)
        ->and($campaign->poolEntries()->count())->toBe(10);
});

it('keeps the session token out of anything serialised', function () {
    $session = ShuffleSession::factory()->create();

    expect($session->toArray())->not->toHaveKey('token')
        ->and($session->token)->toBeString();
});

it('finds the campaign a qualifying purchase would be measured against', function () {
    RewardCampaign::factory()->ended()->create();
    RewardCampaign::factory()->paused()->create();
    $running = RewardCampaign::factory()->active()->create();

    expect(RewardCampaign::query()->running()->first()?->id)->toBe($running->id);
});

it('will not let a customer with a purchase against them be force deleted', function () {
    $customer = Customer::factory()->create();
    Purchase::factory()->create(['customer_id' => $customer->id]);

    expect(fn () => $customer->forceDelete())->toThrow(QueryException::class);
});
