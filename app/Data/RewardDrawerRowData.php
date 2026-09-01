<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\CampaignReward;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# `claimed_share` is decided here, not in the browser, because the panel sorts
# by it - two computations of it would let the sort and the bar disagree.
#[TypeScript(location: ['App', 'Data'])]
class RewardDrawerRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $loaded,
        public int $available,
        public int $claimed,
        public int $void,
        public float $claimed_share,
    ) {}

    /**
     * @param  array{available: int, claimed: int, void: int}|null  $inventory
     */
    public static function fromModel(CampaignReward $attachment, ?array $inventory = null): self
    {
        # A draft has no pool yet; the whole quantity is still only a promise.
        $inventory ??= ['available' => 0, 'claimed' => 0, 'void' => 0];

        $loaded = $attachment->quantity;

        return new self(
            id: $attachment->id,
            name: $attachment->reward->readableName(),
            loaded: $loaded,
            available: $inventory['available'],
            claimed: $inventory['claimed'],
            void: $inventory['void'],
            claimed_share: $loaded === 0
                ? 0.0
                : round(($inventory['claimed'] / $loaded) * 100, 1),
        );
    }
}
