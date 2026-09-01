<?php

use App\Enums\RewardResultStatus;
use App\Enums\ShuffleSessionStatus;
use App\Models\ShuffleResult;
use App\Models\ShuffleSession;

it('closes the turns nobody took before the QR ran out', function () {
    $lapsed = ShuffleSession::factory()->expired()->create();
    $live = ShuffleSession::factory()->create();
    $open = ShuffleSession::factory()->neverExpiring()->create();

    $this->artisan('rewards:expire')->assertSuccessful();

    expect($lapsed->refresh()->status)->toBe(ShuffleSessionStatus::Expired)
        ->and($live->refresh()->status)->toBe(ShuffleSessionStatus::Pending)
        # A turn with no deadline never lapses.
        ->and($open->refresh()->status)->toBe(ShuffleSessionStatus::Pending);
});

it('closes the rewards won and never redeemed in time', function () {
    $lapsed = ShuffleResult::factory()->lapsed()->create();
    $live = ShuffleResult::factory()->create();
    $forever = ShuffleResult::factory()->neverExpiring()->create();

    $this->artisan('rewards:expire')->assertSuccessful();

    expect($lapsed->refresh()->status)->toBe(RewardResultStatus::Expired)
        ->and($live->refresh()->status)->toBe(RewardResultStatus::Unredeemed)
        ->and($forever->refresh()->status)->toBe(RewardResultStatus::Unredeemed);
});

it('leaves a redeemed reward alone even once its date has passed', function () {
    $redeemed = ShuffleResult::factory()->redeemed()->lapsed()->create();

    $this->artisan('rewards:expire')->assertSuccessful();

    expect($redeemed->refresh()->status)->toBe(RewardResultStatus::Redeemed);
});

it('writes nothing when it is only pretending', function () {
    $session = ShuffleSession::factory()->expired()->create();
    $result = ShuffleResult::factory()->lapsed()->create();

    $this->artisan('rewards:expire', ['--pretend' => true])->assertSuccessful();

    expect($session->refresh()->status)->toBe(ShuffleSessionStatus::Pending)
        ->and($result->refresh()->status)->toBe(RewardResultStatus::Unredeemed);
});

it('is safe to run when nothing has lapsed', function () {
    ShuffleSession::factory()->create();

    $this->artisan('rewards:expire')
        ->expectsOutputToContain('Nothing had lapsed.')
        ->assertSuccessful();
});

# The status is tidying, not enforcement: everything reads the date, so a
# host whose cron stopped a week ago still refuses dead rewards.
it('refuses a lapsed turn and a lapsed reward before anything has swept them', function () {
    $session = ShuffleSession::factory()->expired()->create();
    $result = ShuffleResult::factory()->lapsed()->create();

    expect($session->status)->toBe(ShuffleSessionStatus::Pending)
        ->and($session->isShuffleable())->toBeFalse()
        ->and($result->status)->toBe(RewardResultStatus::Unredeemed)
        ->and($result->isRedeemable())->toBeFalse();
});
