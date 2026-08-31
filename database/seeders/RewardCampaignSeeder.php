<?php

namespace Database\Seeders;

use App\Enums\CampaignStatus;
use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Models\Product;
use App\Models\Reward;
use App\Models\RewardCampaign;
use App\Models\User;
use App\Services\Rewards\CampaignService;
use Illuminate\Database\Seeder;

/**
 * The reward catalogue, and one campaign built out of it.
 *
 * Two things in one seeder because one is useless without the other on a fresh
 * database: the catalogue is what the showroom can give away, and the
 * clearance sale is a promotion assembled from it.
 *
 * A hundred and ten rewards in six piles - twenty discounts, twenty-five
 * drawings, twenty audits, twenty complimentary services, fifteen
 * installations and ten baking trays. The proportions are the promotion: there
 * is no probability anywhere in this system, so what somebody can win is
 * exactly what is left in these piles.
 *
 * The trays are the one paired pile, and they are here to make that feature
 * visible rather than to balance the drawer: only a purchase of the oven wins
 * one, so a demo database carries a reward most sales cannot reach alongside
 * five they can - see `campaign_reward_product`.
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

    /** The appliance the trays are paired to. */
    private const QUALIFYING_SKU = 'SHF-OVEN-60';

    /** The reward that appliance wins. */
    private const TRAY_SKU = 'SHF-TRAY-SET';

    /**
     * The catalogue, and how many of each the clearance sale loads.
     *
     * `quantity` is the campaign's decision and is peeled off below;
     * everything else describes the reward itself and is written once into
     * `rewards`.
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
            [
                'name' => 'Baking tray set',
                'type' => RewardType::Product,
                'description' => 'A three-piece baking tray set, sized for the oven it comes with.',
                'quantity' => 10,
                'validity_days' => 90,
                'terms' => 'Collected from the showroom with the appliance.',
                /* The two product links, and they are different things: the
                   tray is what is won, the oven is what wins it. */
                'product_sku' => self::TRAY_SKU,
                'qualifying_skus' => [self::QUALIFYING_SKU],
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

        $products = $this->products();

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

        foreach (self::rewards() as $definition) {
            $this->attach($campaign, $definition, $products, $owner);
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

    /**
     * The two products the paired reward needs, made only if the floor does
     * not already carry them.
     *
     * Keyed on SKU rather than name: a showroom that has renamed its oven
     * should not be given a second one the next time this runs.
     *
     * @return array<string, Product>
     */
    private function products(): array
    {
        $wanted = [
            self::QUALIFYING_SKU => 'Gas oven, 60cm',
            self::TRAY_SKU => 'Baking tray set',
        ];

        $products = [];

        foreach ($wanted as $sku => $name) {
            $products[$sku] = Product::query()->firstOrCreate(
                ['sku' => $sku],
                ['name' => $name],
            );
        }

        return $products;
    }

    /**
     * Writes one catalogue reward and hangs it on the campaign.
     *
     * The reward is matched on its name, so a second campaign seeded later
     * reuses the row rather than describing the same audit over again - which
     * is the whole point of there being a catalogue.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, Product>  $products
     */
    private function attach(
        RewardCampaign $campaign,
        array $definition,
        array $products,
        ?User $owner,
    ): void {
        $productSku = $definition['product_sku'] ?? null;
        $qualifyingSkus = $definition['qualifying_skus'] ?? [];

        $reward = Reward::query()->firstOrCreate(
            ['name' => $definition['name']],
            [
                'description' => $definition['description'] ?? null,
                'type' => $definition['type'],
                'product_id' => $productSku === null ? null : $products[$productSku]->id,
                'value' => $definition['value'] ?? null,
                'value_unit' => $definition['value_unit'] ?? null,
                'terms' => $definition['terms'] ?? null,
                'default_validity_days' => $definition['validity_days'] ?? null,
                'is_active' => true,
                'created_by' => $owner?->id,
            ],
        );

        $attachment = $campaign->rewards()->create([
            'reward_id' => $reward->id,
            'quantity' => $definition['quantity'],
            'validity_days' => $definition['validity_days'] ?? null,
            'is_active' => true,
        ]);

        if ($qualifyingSkus !== []) {
            $attachment->qualifyingProducts()->sync(
                array_map(fn (string $sku): int => $products[$sku]->id, $qualifyingSkus),
            );
        }
    }
}
