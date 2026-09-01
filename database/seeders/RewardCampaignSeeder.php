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

# =========================================================================
# The reward catalogue, and one campaign built out of it
# =========================================================================
#
# Published through `CampaignService`, never by writing pool rows here: that
# is where the pool is generated and the one-campaign-at-a-time rule enforced.
# Repeatable, and it leaves an existing sale alone - re-running must never
# hand out a second hundred rewards against a campaign already in play.

class RewardCampaignSeeder extends Seeder
{
    private const NAME = 'Clearance Sale';

    /** The appliance the trays are paired to. */
    private const QUALIFYING_SKU = 'SHF-OVEN-60';

    /** The reward that appliance wins. */
    private const TRAY_SKU = 'SHF-TRAY-SET';

    /**
     * `quantity` is the campaign's decision and is peeled off below;
     * everything else is written once into `rewards`.
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

        $owner = User::query()->orderBy('id')->first();

        $products = $this->products();

        $campaign = RewardCampaign::query()->create([
            'name' => self::NAME,
            'description' => 'Clearing the floor. Every completed purchase over the threshold earns one shuffle.',
            'status' => CampaignStatus::Draft,
            'starts_at' => null,
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
     * Keyed on SKU: a showroom that has renamed its oven must not be given a
     * second one on the next run.
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
     * Matched on name, so a later campaign reuses the catalogue row rather
     * than describing the same audit over again.
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
