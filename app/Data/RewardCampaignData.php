<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CampaignStatus;
use App\Models\RewardCampaign;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A campaign as the rewards screens read it.
 *
 * `is_running` is the model's three-part answer rather than the status alone:
 * a campaign marked active whose end date passed last week is not running, and
 * a screen reading only the status would say it was.
 *
 * @property array<int, CampaignRewardData> $rewards
 */
#[TypeScript(location: ['App', 'Data'])]
class RewardCampaignData extends Data
{
    /**
     * @param  array<int, CampaignRewardData>  $rewards
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public CampaignStatus $status,
        public string $status_label,
        public bool $is_published,
        public bool $is_running,
        public ?string $starts_at,
        public ?string $ends_at,
        public int $max_shuffles_per_customer,
        public ?string $minimum_purchase_amount,
        public int $loaded,
        public int $available,
        public int $claimed,
        public int $void,
        public int $turns_given,
        public array $rewards = [],
    ) {}

    /**
     * @param  array<int, array{available: int, claimed: int, void: int}>  $inventory  keyed by reward id
     */
    public static function fromModel(
        RewardCampaign $campaign,
        array $inventory = [],
        bool $withRewards = true,
    ): self {
        $rewards = $withRewards && $campaign->relationLoaded('rewards')
            ? $campaign->rewards
                ->map(fn ($reward) => CampaignRewardData::fromModel($reward, $inventory[$reward->id] ?? null))
                ->all()
            : [];

        $totals = ['available' => 0, 'claimed' => 0, 'void' => 0];

        foreach ($inventory as $counts) {
            foreach ($totals as $state => $ignored) {
                $totals[$state] += $counts[$state];
            }
        }

        return new self(
            id: $campaign->id,
            name: $campaign->name,
            description: $campaign->description,
            status: $campaign->status,
            status_label: $campaign->status->label(),
            is_published: $campaign->status->isPublished(),
            is_running: $campaign->isRunning(),
            starts_at: $campaign->starts_at?->toDateTimeString(),
            ends_at: $campaign->ends_at?->toDateTimeString(),
            max_shuffles_per_customer: $campaign->max_shuffles_per_customer,
            minimum_purchase_amount: $campaign->minimum_purchase_amount,
            loaded: array_sum($totals),
            available: $totals['available'],
            claimed: $totals['claimed'],
            void: $totals['void'],
            turns_given: $campaign->sessions_count ?? 0,
            rewards: $rewards,
        );
    }
}
