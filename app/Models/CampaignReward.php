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
 * One catalogue reward put into one campaign, and how many of it exist.
 *
 * An attachment rather than a reward. What the thing is belongs to `Reward`
 * and is not repeated here; this row carries only what the campaign decides -
 * `quantity`, `validity_days`, whether it is switched on, and which products
 * a customer must have bought to be in the running for it.
 *
 * `quantity` is what was loaded and never falls; what is left is counted off
 * the pool, because that is the only place that can answer it correctly while
 * somebody is claiming a row.
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
     * What is being handed out. Everything readable about this attachment -
     * its name, type, worth and terms - is read through here.
     *
     * @return BelongsTo<Reward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /**
     * What somebody has to have bought to be in the running for this.
     *
     * Empty is the common case and means any purchase qualifies - see
     * `campaign_reward_product`. A pairing is the exception, not the rule.
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
     * Whether a purchase of this product would be in the running for this
     * reward.
     *
     * A reward that names no products qualifies against anything, which is
     * what makes pairing an opt-in rather than something every campaign has to
     * remember to switch off.
     *
     * Reads the loaded relation where there is one, so checking a campaign's
     * whole set costs one query rather than one per reward.
     */
    public function qualifiesFor(?int $productId): bool
    {
        /** @var Collection<int, Product> $named */
        $named = $this->relationLoaded('qualifyingProducts')
            ? $this->qualifyingProducts
            : $this->qualifyingProducts()->get();

        if ($named->isEmpty()) {
            return true;
        }

        return $productId !== null
            && $named->contains(fn (Product $product): bool => $product->id === $productId);
    }

    /** How many units of this reward are still there to be won. */
    public function availableCount(): int
    {
        return $this->poolEntries()
            ->where('status', PoolEntryStatus::Available)
            ->count();
    }

    /**
     * When a reward won right now would lapse.
     *
     * Read at the moment of winning and stamped onto the result, never
     * recomputed - see `shuffle_results.expires_at`. Null means it does not
     * lapse at all.
     */
    public function expiryFrom(CarbonImmutable $wonAt): ?CarbonImmutable
    {
        return $this->validity_days === null
            ? null
            : $wonAt->addDays($this->validity_days);
    }
}
