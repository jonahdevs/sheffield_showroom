<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CampaignStatus;
use App\Models\RewardCampaign;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class RewardCampaignSummaryData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public CampaignStatus $status,
        public string $status_label,
        public string $ran,
        public int $loaded,
        public int $won,
        public int $redeemed,
        # Null, not nought, when nothing was won: nought reads as an audience
        # that ignored the promotion rather than one that never played it.
        public ?float $collection_rate,
    ) {}

    public static function fromModel(
        RewardCampaign $campaign,
        int $loaded,
        int $won,
        int $redeemed,
    ): self {
        return new self(
            id: $campaign->id,
            name: $campaign->name,
            status: $campaign->status,
            status_label: $campaign->status->label(),
            ran: self::ran($campaign),
            loaded: $loaded,
            won: $won,
            redeemed: $redeemed,
            collection_rate: $won === 0
                ? null
                : round(($redeemed / $won) * 100, 1),
        );
    }

    private static function ran(RewardCampaign $campaign): string
    {
        $from = self::day($campaign->starts_at);
        $to = self::day($campaign->ends_at);

        return match (true) {
            $from === null && $to === null => 'No dates set',
            $from === null => 'Until '.$to,
            $to === null => 'From '.$from,
            default => $from.' to '.$to,
        };
    }

    private static function day(?CarbonImmutable $at): ?string
    {
        return $at?->format('j M Y');
    }
}
