<?php

namespace Database\Seeders;

use App\Enums\CampaignStatus;
use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Models\RewardCampaign;
use App\Models\User;
use App\Services\Rewards\CampaignService;
use Illuminate\Database\Seeder;

/**
 * One campaign to see the feature working: the clearance sale.
 *
 * A hundred rewards in the shape the architecture document describes - twenty
 * discounts, twenty-five drawings, twenty audits, twenty complimentary
 * services and fifteen installations. The proportions are the promotion: there
 * is no probability anywhere in this system, so what somebody can win is
 * exactly what is left in these five piles.
 *
 * Published through `CampaignService` rather than by writing rows here,
 * because publishing is where the pool is generated and where the
 * one-campaign-at-a-time rule is enforced. A seeder that inserted its own pool
 * entries would be a second way of loading a drawer, and the one nobody
 * tested.
 *
 * Repeatable. It leaves an existing clearance sale exactly as it is - claimed
 * rewards and all - because re-running a seeder must never quietly hand out a
 * second hundred rewards against a campaign customers are already playing.
 */
class RewardCampaignSeeder extends Seeder
{
    private const NAME = 'Clearance Sale';

    /**
     * The drawer, as the showroom would load it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rewards(): array
    {
        return [
            [
                'name' => '10% discount',
                'type' => RewardType::Discount,
                'description' => 'Ten per cent off the next purchase.',
                'value' => 10,
                'value_unit' => RewardValueUnit::Percentage,
                'quantity' => 20,
                'validity_days' => 90,
                'terms' => 'One purchase, in this showroom, within ninety days. Not redeemable against an existing quotation.',
            ],
            [
                'name' => 'Free drawing and layout',
                'type' => RewardType::DrawingLayout,
                'description' => 'A measured kitchen drawing and layout, prepared by the design desk.',
                'quantity' => 25,
                'validity_days' => 120,
                'terms' => 'One room. Site measurements taken by appointment.',
            ],
            [
                'name' => 'Free kitchen audit',
                'type' => RewardType::KitchenAudit,
                'description' => 'A survey of the existing kitchen and what it would take to bring it up.',
                'quantity' => 20,
                'validity_days' => 120,
                'terms' => 'Within twenty-five kilometres of the showroom.',
            ],
            [
                'name' => 'One complimentary service',
                'type' => RewardType::ComplimentaryService,
                'description' => 'One service visit on equipment bought here.',
                'quantity' => 20,
                'validity_days' => 180,
                'terms' => 'Labour only. Parts, where any are needed, are charged.',
            ],
            [
                'name' => 'Free installation',
                'type' => RewardType::Installation,
                'description' => 'Installation of the equipment bought on the qualifying purchase.',
                'quantity' => 15,
                'validity_days' => 180,
                'terms' => 'Standard installation. Plumbing, gas and electrical works beyond the appliance are quoted separately.',
            ],
        ];
    }

    public function run(): void
    {
        $existing = RewardCampaign::query()->where('name', self::NAME)->first();

        if ($existing !== null) {
            $this->command?->info(sprintf(
                'The %s campaign is already on file (%s, %d of %d rewards left). Left as it is.',
                self::NAME,
                $existing->status->label(),
                $existing->availableCount(),
                $existing->poolEntries()->count(),
            ));

            return;
        }

        /* Whoever the first super admin is. The campaign has to be attributed
           to somebody, and on a fresh database that is the account
           `UsersSeeder` just made. */
        $owner = User::query()->orderBy('id')->first();

        $campaign = RewardCampaign::query()->create([
            'name' => self::NAME,
            'description' => 'Clearing the floor. Every completed purchase over the threshold earns one shuffle.',
            'status' => CampaignStatus::Draft,
            'starts_at' => null,
            /* Open at both ends. A showroom stops this by pausing it, not by
               waiting for a date nobody remembers setting. */
            'ends_at' => null,
            'max_shuffles_per_customer' => 1,
            'minimum_purchase_amount' => 100000,
        ]);

        if ($owner !== null) {
            $campaign->forceFill(['created_by' => $owner->id])->save();
        }

        foreach (self::rewards() as $reward) {
            $campaign->rewards()->create($reward + ['is_active' => true]);
        }

        /* Only one campaign runs at a time, so a database that already has one
           gets this as a draft rather than an exception - somebody can start
           it from the Rewards screen when they are ready. */
        $running = RewardCampaign::query()
            ->where('status', CampaignStatus::Active)
            ->whereKeyNot($campaign->id)
            ->first();

        if ($running !== null) {
            $this->command?->warn(sprintf(
                '%s is already running, so %s has been left as a draft. Publish it from the Rewards screen once that one is paused.',
                $running->name,
                self::NAME,
            ));

            return;
        }

        $loaded = app(CampaignService::class)->publish($campaign);

        $this->command?->info(sprintf(
            '%s is live with %d rewards, for purchases over KSh %s.',
            self::NAME,
            $loaded,
            number_format((float) $campaign->minimum_purchase_amount, 2),
        ));
    }
}
