<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PoolEntryStatus;
use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use Carbon\CarbonImmutable;
use Database\Factories\CampaignRewardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One kind of reward inside a campaign, and how many of it exist.
 *
 * A definition rather than a reward. `quantity` is what was loaded and never
 * falls; what is left is counted off the pool, because that is the only place
 * that can answer it correctly while somebody is claiming a row.
 *
 * @property int $id
 * @property int $campaign_id
 * @property string $name
 * @property string|null $description
 * @property RewardType $type
 * @property string|null $value
 * @property RewardValueUnit|null $value_unit
 * @property int $quantity
 * @property int|null $validity_days
 * @property string|null $terms
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
        'name',
        'description',
        'type',
        'value',
        'value_unit',
        'quantity',
        'validity_days',
        'terms',
        'is_active',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => RewardType::class,
            'value' => 'decimal:2',
            'value_unit' => RewardValueUnit::class,
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
     * @return HasMany<RewardPoolEntry, $this>
     */
    public function poolEntries(): HasMany
    {
        return $this->hasMany(RewardPoolEntry::class);
    }

    /**
     * The number on the card as somebody reads it: "10%" rather than "10".
     *
     * Null when the reward carries no figure, which is most of them - a free
     * kitchen audit is worth what its terms say it is.
     */
    public function readableValue(): ?string
    {
        if ($this->value === null || $this->value_unit === null) {
            return null;
        }

        return $this->value_unit->format((float) $this->value);
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
