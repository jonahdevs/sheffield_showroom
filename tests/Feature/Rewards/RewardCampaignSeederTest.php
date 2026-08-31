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
        /* The attachment's own number and the rows behind it have to agree, or
           the campaign is promising something the drawer cannot hand over. */
        ->and($loaded->sum())->toBe(110);
});

/**
 * The demo database has to show the pairing off, or nobody opening it would
 * know the feature is there.
 */
it('pairs the tray to the oven and leaves every other pile open', function () {
    $this->seed(RewardCampaignSeeder::class);

    $campaign = RewardCampaign::query()->where('name', 'Clearance Sale')->sole();

    $tray = $campaign->rewards()
        ->whereRelation('reward', 'name', 'Baking tray set')
        ->sole();

    expect($tray->reward->type)->toBe(RewardType::Product)
        /* What is won is the tray... */
        ->and($tray->reward->product->sku)->toBe('SHF-TRAY-SET')
        /* ...and what wins it is the oven. Two different products. */
        ->and($tray->qualifyingProducts->pluck('sku')->all())->toBe(['SHF-OVEN-60']);

    $open = $campaign->rewards()
        ->whereRelation('reward', 'name', '!=', 'Baking tray set')
        ->get();

    expect($open)->toHaveCount(5)
        ->and($open->every(fn ($attachment) => $attachment->qualifyingProducts()->count() === 0))
        ->toBeTrue();
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
        ->and($campaign->poolEntries()->count())->toBe(110)
        ->and($campaign->availableCount())->toBe(109)
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
        ->and($clearance->rewards()->count())->toBe(6)
        /* Defined but not loaded: the pool is written at publication. */
        ->and($clearance->poolEntries()->count())->toBe(0)
        ->and($running->refresh()->status)->toBe(CampaignStatus::Active);
});
