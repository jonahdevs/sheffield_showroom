<?php

use App\Enums\CampaignStatus;
use App\Enums\PoolEntryStatus;
use App\Enums\RewardResultStatus;
use App\Enums\ShuffleSessionStatus;
use App\Exceptions\ShuffleUnavailableException;
use App\Models\CampaignReward;
use App\Models\RewardCampaign;
use App\Models\RewardPoolEntry;
use App\Models\ShuffleResult;
use App\Models\ShuffleSession;
use App\Services\Rewards\CampaignService;
use App\Services\Rewards\ShuffleRewardService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

// -----------------------------------------------------------------------------
// Claiming a reward
// -----------------------------------------------------------------------------

it('claims one unit, writes one result, and spends the turn', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 5]);
    $session = sessionOn($campaign);

    $result = app(ShuffleRewardService::class)->claim($session);

    expect($result->status)->toBe(RewardResultStatus::Unredeemed)
        ->and($result->code)->toStartWith('SHF-')
        ->and($session->refresh()->status)->toBe(ShuffleSessionStatus::Shuffled)
        ->and($result->poolEntry->status)->toBe(PoolEntryStatus::Claimed)
        ->and($result->poolEntry->claimed_at)->not->toBeNull()
        /* Four left of five, counted off the pool rather than off anything
           the reward definition remembers. */
        ->and($campaign->availableCount())->toBe(4)
        ->and(ShuffleResult::query()->count())->toBe(1);
});

it('refuses a second shuffle on the same turn', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 5]);
    $session = sessionOn($campaign);

    $service = app(ShuffleRewardService::class);
    $service->claim($session);

    expect(fn () => $service->claim($session->refresh()))
        ->toThrow(ShuffleUnavailableException::class);

    /* And nothing was taken for the attempt. */
    expect($campaign->availableCount())->toBe(4)
        ->and(ShuffleResult::query()->count())->toBe(1);
});

it('never hands out more rewards than were loaded', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 3]);
    $service = app(ShuffleRewardService::class);

    foreach (range(1, 3) as $ignored) {
        $service->claim(sessionOn($campaign));
    }

    expect($campaign->availableCount())->toBe(0)
        ->and(ShuffleResult::query()->count())->toBe(3);

    /* The fourth customer is told the drawer is empty rather than being handed
       a fourth of three. */
    expect(fn () => $service->claim(sessionOn($campaign)))
        ->toThrow(ShuffleUnavailableException::class);
});

/**
 * The turn survives an empty pool. The customer did nothing wrong, so they
 * keep it and a showroom that adds stock back finds them still holding it.
 */
it('spends nothing when the pool is empty', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 1]);
    $service = app(ShuffleRewardService::class);

    $service->claim(sessionOn($campaign));

    $unlucky = sessionOn($campaign);

    expect(fn () => $service->claim($unlucky))->toThrow(ShuffleUnavailableException::class);

    expect($unlucky->refresh()->status)->toBe(ShuffleSessionStatus::Pending);
});

it('refuses a turn whose window has closed', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 5]);
    $session = ShuffleSession::factory()->expired()->create(['campaign_id' => $campaign->id]);

    expect(fn () => app(ShuffleRewardService::class)->claim($session))
        ->toThrow(ShuffleUnavailableException::class);

    expect($campaign->availableCount())->toBe(5);
});

it('refuses a turn once its campaign has stopped', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 5]);
    $session = sessionOn($campaign);

    app(CampaignService::class)->pause($campaign);

    expect(fn () => app(ShuffleRewardService::class)->claim($session))
        ->toThrow(ShuffleUnavailableException::class);

    expect($campaign->availableCount())->toBe(5);
});

/**
 * Stamped at the moment of winning, never recomputed. An administrator
 * lengthening the validity afterwards must not move a deadline somebody was
 * already given.
 */
it('stamps the expiry from the reward definition and leaves it there', function () {
    $wonAt = CarbonImmutable::parse('2026-08-31 12:00:00');

    $campaign = RewardCampaign::factory()->create(['status' => CampaignStatus::Draft]);
    $reward = CampaignReward::factory()->quantity(1)->create([
        'campaign_id' => $campaign->id,
        'validity_days' => 30,
    ]);
    app(CampaignService::class)->publish($campaign);

    $result = app(ShuffleRewardService::class)->claim(sessionOn($campaign->refresh()), $wonAt);

    expect($result->expires_at->toDateTimeString())->toBe('2026-09-30 12:00:00');

    $reward->forceFill(['validity_days' => 365])->save();

    expect($result->refresh()->expires_at->toDateTimeString())->toBe('2026-09-30 12:00:00');
});

it('gives every reward a code somebody can read back over a counter', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 12]);
    $service = app(ShuffleRewardService::class);

    $codes = collect(range(1, 12))
        ->map(fn () => $service->claim(sessionOn($campaign))->code);

    expect($codes->unique())->toHaveCount(12)
        ->and($codes->every(fn (string $code) => (bool) preg_match('/^SHF-[A-HJ-NP-Z2-9]{6}$/', $code)))
        ->toBeTrue();
});

// -----------------------------------------------------------------------------
// Two people at once
// -----------------------------------------------------------------------------

/**
 * The failure this whole feature is built to avoid, provoked directly.
 *
 * Two transactions cannot genuinely run at once inside one test process, so
 * this attacks the invariant from the other side: it takes the unit the
 * service is about to claim and gives it away underneath, which is exactly the
 * state a lost row lock would produce. The unique index on
 * `reward_pool_entry_id` is what has to refuse - and it does, so a broken lock
 * one day becomes an error rather than two people holding the same reward.
 */
it('cannot write two results against one reward unit', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 1]);

    $entry = RewardPoolEntry::query()->where('campaign_id', $campaign->id)->sole();

    ShuffleResult::factory()->create([
        'shuffle_session_id' => sessionOn($campaign)->id,
        'reward_pool_entry_id' => $entry->id,
    ]);

    expect(fn () => ShuffleResult::factory()->create([
        'shuffle_session_id' => sessionOn($campaign)->id,
        'reward_pool_entry_id' => $entry->id,
    ]))->toThrow(QueryException::class);

    expect(ShuffleResult::query()->count())->toBe(1);
});

/**
 * Every unit is accounted for, in every state, at every point - which is what
 * §25 asks the reporting to reconcile.
 */
it('reconciles loaded against available, claimed and void', function () {
    $campaign = campaignHolding(['Free kitchen audit' => 10]);
    $service = app(ShuffleRewardService::class);

    foreach (range(1, 4) as $ignored) {
        $service->claim(sessionOn($campaign));
    }

    RewardPoolEntry::query()
        ->where('campaign_id', $campaign->id)
        ->where('status', PoolEntryStatus::Available)
        ->limit(2)
        ->update(['status' => PoolEntryStatus::Void]);

    $counts = $campaign->poolEntries()
        ->selectRaw('status, count(*) as aggregate')
        ->groupBy('status')
        ->pluck('aggregate', 'status');

    expect((int) $counts->get('claimed'))->toBe(4)
        ->and((int) $counts->get('void'))->toBe(2)
        ->and((int) $counts->get('available'))->toBe(4)
        ->and($campaign->poolEntries()->count())->toBe(10);
});
