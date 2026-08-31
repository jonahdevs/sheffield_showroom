<?php

use App\Enums\CampaignStatus;
use App\Enums\PoolEntryStatus;
use App\Models\RewardCampaign;
use App\Services\Rewards\ShuffleRewardService;
use Database\Seeders\RewardCampaignSeeder;

it('loads the clearance sale with a hundred rewards', function () {
    $this->seed(RewardCampaignSeeder::class);

    $campaign = RewardCampaign::query()->where('name', 'Clearance Sale')->sole();

    expect($campaign->status)->toBe(CampaignStatus::Active)
        ->and($campaign->isRunning())->toBeTrue()
        ->and($campaign->rewards()->count())->toBe(5)
        ->and($campaign->poolEntries()->count())->toBe(100)
        ->and($campaign->availableCount())->toBe(100)
        ->and((float) $campaign->minimum_purchase_amount)->toBe(100000.0);
});

it('loads each pile in the proportion the promotion promises', function () {
    $this->seed(RewardCampaignSeeder::class);

    $campaign = RewardCampaign::query()->where('name', 'Clearance Sale')->sole();

    $loaded = $campaign->rewards()
        ->get()
        ->mapWithKeys(fn ($reward) => [$reward->name => $reward->poolEntries()->count()]);

    expect($loaded->all())->toBe([
        '10% discount' => 20,
        'Free drawing and layout' => 25,
        'Free kitchen audit' => 20,
        'One complimentary service' => 20,
        'Free installation' => 15,
    ])
        /* The definition's own number and the rows behind it have to agree, or
           the campaign is promising something the drawer cannot hand over. */
        ->and($loaded->sum())->toBe(100);
});

/**
 * The trap a seeder like this has to avoid: re-running it while a promotion is
 * live would quietly load a second hundred rewards against a campaign
 * customers are already playing.
 */
it('leaves an existing clearance sale exactly as it is', function () {
    $this->seed(RewardCampaignSeeder::class);

    $campaign = RewardCampaign::query()->where('name', 'Clearance Sale')->sole();

    app(ShuffleRewardService::class)->claim(sessionOn($campaign));

    $this->seed(RewardCampaignSeeder::class);
    $this->seed(RewardCampaignSeeder::class);

    expect(RewardCampaign::query()->where('name', 'Clearance Sale')->count())->toBe(1)
        ->and($campaign->poolEntries()->count())->toBe(100)
        ->and($campaign->availableCount())->toBe(99)
        ->and($campaign->poolEntries()->where('status', PoolEntryStatus::Claimed)->count())->toBe(1);
});

/**
 * Only one campaign runs at a time. Seeding into a database that already has
 * one has to land as a draft rather than throwing, or a routine `db:seed`
 * fails on a showroom mid-promotion.
 */
it('lands as a draft when another campaign is already running', function () {
    $running = campaignHolding(['Audit' => 5]);

    $this->seed(RewardCampaignSeeder::class);

    $clearance = RewardCampaign::query()->where('name', 'Clearance Sale')->sole();

    expect($clearance->status)->toBe(CampaignStatus::Draft)
        ->and($clearance->rewards()->count())->toBe(5)
        /* Defined but not loaded: the pool is written at publication. */
        ->and($clearance->poolEntries()->count())->toBe(0)
        ->and($running->refresh()->status)->toBe(CampaignStatus::Active);
});
