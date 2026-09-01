<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CampaignStatus;
use App\Models\RewardCampaign;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class RewardHeadlineData extends Data
{
    public function __construct(
        public RewardCampaignData $campaign,
        public ?string $dormant_reason,
        # Truncated, not rounded up: twelve and a half days left is twelve.
        public ?int $days_remaining,
    ) {}

    /**
     * @param  array<int, array{available: int, claimed: int, void: int}>  $inventory  keyed by campaign_reward_id
     */
    public static function fromModel(
        RewardCampaign $campaign,
        array $inventory = [],
        ?CarbonImmutable $at = null,
    ): self {
        $at ??= CarbonImmutable::now();
        $running = $campaign->isRunning($at);

        return new self(
            campaign: RewardCampaignData::fromModel($campaign, $inventory, withRewards: false),
            dormant_reason: self::dormantReason($campaign, $at, $running),
            days_remaining: $running ? self::daysRemaining($campaign, $at) : null,
        );
    }

    # `status` is only the first of the three questions `isRunning()` asks, so
    # the two disagree once the calendar passes a campaign nobody marked
    # completed. Do not read the status alone and call it running.
    private static function dormantReason(
        RewardCampaign $campaign,
        CarbonImmutable $at,
        bool $running,
    ): ?string {
        if ($campaign->status !== CampaignStatus::Active || $running) {
            return null;
        }

        if ($campaign->starts_at !== null && $at->lessThan($campaign->starts_at)) {
            return 'Not started yet';
        }

        return 'Past its end date';
    }

    private static function daysRemaining(RewardCampaign $campaign, CarbonImmutable $at): ?int
    {
        if ($campaign->ends_at === null) {
            return null;
        }

        return max(0, (int) $at->diffInDays($campaign->ends_at));
    }
}
