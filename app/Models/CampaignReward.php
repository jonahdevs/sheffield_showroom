<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PoolEntryStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CampaignRewardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * `quantity` is what was loaded and never falls; what is left is counted off
 * the pool, the only place that can answer it while somebody is claiming a row.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $reward_id
 * @property int $quantity
 * @property int|null $validity_days
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CampaignReward extends Model
{
    /** @use HasFactory<CampaignRewardFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'reward_id',
        'quantity',
        'validity_days',
        'is_active',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'validity_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RewardCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(RewardCampaign::class, 'campaign_id');
    }

    /**
     * @return BelongsTo<Reward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /**
     * Empty is the common case and means any purchase qualifies.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function qualifyingProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'campaign_reward_product');
    }

    /**
     * @return HasMany<RewardPoolEntry, $this>
     */
    public function poolEntries(): HasMany
    {
        return $this->hasMany(RewardPoolEntry::class);
    }

    /**
     * A reward naming no products qualifies against anything, and any one
     * match is enough: "buy the oven", not "buy only the oven".
     *
     * @param  array<int, int>  $productIds
     */
    public function qualifiesFor(array $productIds): bool
    {
        /** @var Collection<int, Product> $named */
        $named = $this->relationLoaded('qualifyingProducts')
            ? $this->qualifyingProducts
            : $this->qualifyingProducts()->get();

        if ($named->isEmpty()) {
            return true;
        }

        return $named->contains(
            fn (Product $product): bool => in_array($product->id, $productIds, true),
        );
    }

    public function availableCount(): int
    {
        return $this->poolEntries()
            ->where('status', PoolEntryStatus::Available)
            ->count();
    }

    # Stamped onto the result at win time and never recomputed, so retuning
    # `validity_days` cannot move a deadline somebody already has.
    public function expiryFrom(CarbonImmutable $wonAt): ?CarbonImmutable
    {
        return $this->validity_days === null
            ? null
            : $wonAt->addDays($this->validity_days);
    }
}
