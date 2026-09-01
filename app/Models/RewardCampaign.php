<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\PoolEntryStatus;
use App\Policies\RewardCampaignPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\RewardCampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CampaignStatus $status
 * @property int $max_shuffles_per_customer
 * @property string|null $minimum_purchase_amount
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UsePolicy(RewardCampaignPolicy::class)]
class RewardCampaign extends Model
{
    /** @use HasFactory<RewardCampaignFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'starts_at',
        'ends_at',
        'status',
        'max_shuffles_per_customer',
        'minimum_purchase_amount',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => CampaignStatus::class,
            'max_shuffles_per_customer' => 'integer',
            'minimum_purchase_amount' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<CampaignReward, $this>
     */
    public function rewards(): HasMany
    {
        return $this->hasMany(CampaignReward::class, 'campaign_id');
    }

    /**
     * Reached straight off the campaign, never through the definitions - that
     * is what the pool's denormalised `campaign_id` is for.
     *
     * @return HasMany<RewardPoolEntry, $this>
     */
    public function poolEntries(): HasMany
    {
        return $this->hasMany(RewardPoolEntry::class, 'campaign_id');
    }

    /**
     * @return HasMany<ShuffleSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ShuffleSession::class, 'campaign_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRunning(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        if ($this->status !== CampaignStatus::Active) {
            return false;
        }

        if ($this->starts_at !== null && $at->lessThan($this->starts_at)) {
            return false;
        }

        if ($this->ends_at !== null && $at->greaterThan($this->ends_at)) {
            return false;
        }

        return true;
    }

    public function availableCount(): int
    {
        return $this->poolEntries()
            ->where('status', PoolEntryStatus::Available)
            ->count();
    }

    /**
     * At most one campaign may be active at a time, held by `CampaignService`
     * because MySQL has no partial unique index to say it with. Ordered
     * deterministically rather than trusting that only one came back.
     *
     * @param  Builder<RewardCampaign>  $query
     */
    #[Scope]
    protected function running(Builder $query, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        $query->where('status', CampaignStatus::Active)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $inner) => $inner
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>=', $at))
            ->orderBy('id');
    }
}
