<?php

use App\Enums\CampaignStatus;
use App\Enums\PoolEntryStatus;
use App\Enums\RewardType;
use App\Models\RewardCampaign;
use App\Services\Rewards\ShuffleRewardService;
use Database\Seeders\RewardCampaignSeeder;

it('loads the clearance sale with a hundred and ten rewards', function () {
    $this->seed(RewardCampaignSeeder::class);

    $campaign = RewardCampaign::query()->where('name', 'Clearance Sale')->sole();

    expect($campaign->status)->toBe(CampaignStatus::Active)
        ->and($campaign->isRunning())->toBeTrue()
        ->and($campaign->rewards()->count())->toBe(6)
        ->and($campaign->poolEntries()->count())->toBe(110)
        ->and($campaign->availableCount())->toBe(110)
        ->and((float) $campaign->minimum_purchase_amount)->toBe(100000.0);
});

it('loads each pile in the proportion the promotion promises', function () {
    $this->seed(RewardCampaignSeeder::class);

    $campaign = RewardCampaign::query()->where('name', 'Clearance Sale')->sole();

    $loaded = $campaign->rewards()
        ->with('reward')
        ->get()
        ->mapWithKeys(fn ($attachment) => [
            $attachment->reward->name => $attachment->poolEntries()->count(),
        ]);

    expect($loaded->all())->toBe([
        '10% discount' => 20,
        'Free drawing and layout' => 25,
        'Free kitchen audit' => 20,
        'One complimentary service' => 20,
        'Free installation' => 15,
        'Baking tray set' => 10,
    ])
        # The attachment quantities and the pool rows behind them must agree.
        ->and($loaded->sum())->toBe(110);
});

it('pairs the tray to the oven and leaves every other pile open', function () {
    $this->seed(RewardCampaignSeeder::class);

    $campaign = RewardCampaign::query()->where('name', 'Clearance Sale')->sole();

    $tray = $campaign->rewards()
        ->whereRelation('reward', 'name', 'Baking tray set')
        ->sole();

    expect($tray->reward->type)->toBe(RewardType::Product)
        # Won: the tray. Wins it: the oven. Two different products.
        ->and($tray->reward->product->sku)->toBe('SHF-TRAY-SET')
        ->and($tray->qualifyingProducts->pluck('sku')->all())->toBe(['SHF-OVEN-60']);

    $open = $campaign->rewards()
        ->whereRelation('reward', 'name', '!=', 'Baking tray set')
        ->get();

    expect($open)->toHaveCount(5)
        ->and($open->every(fn ($attachment) => $attachment->qualifyingProducts()->count() === 0))
        ->toBeTrue();
});

it('leaves an existing clearance sale exactly as it is', function () {
    $this->seed(RewardCampaignSeeder::class);

    $campaign = RewardCampaign::query()->where('name', 'Clearance Sale')->sole();

    app(ShuffleRewardService::class)->claim(sessionOn($campaign));

    $this->seed(RewardCampaignSeeder::class);
    $this->seed(RewardCampaignSeeder::class);

    expect(RewardCampaign::query()->where('name', 'Clearance Sale')->count())->toBe(1)
        ->and($campaign->poolEntries()->count())->toBe(110)
        ->and($campaign->availableCount())->toBe(109)
        ->and($campaign->poolEntries()->where('status', PoolEntryStatus::Claimed)->count())->toBe(1);
});

it('lands as a draft when another campaign is already running', function () {
    $running = campaignHolding(['Audit' => 5]);

    $this->seed(RewardCampaignSeeder::class);

    $clearance = RewardCampaign::query()->where('name', 'Clearance Sale')->sole();

    expect($clearance->status)->toBe(CampaignStatus::Draft)
        ->and($clearance->rewards()->count())->toBe(6)
        ->and($clearance->poolEntries()->count())->toBe(0)
        ->and($running->refresh()->status)->toBe(CampaignStatus::Active);
});
