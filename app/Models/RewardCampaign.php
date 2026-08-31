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
 * One promotion, and the pool of rewards behind it.
 *
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
     * Every reward unit in the campaign, whatever state it is in.
     *
     * Reached straight off the campaign rather than through the definitions,
     * which is what the denormalised `campaign_id` on the pool is for - see
     * that migration.
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

    /**
     * Whether this campaign is handing out rewards at this moment.
     *
     * Three separate questions, and all three have to be yes: somebody set it
     * running, the calendar agrees, and there is something left to win. The
     * last one is asked here rather than assumed because a campaign that has
     * given away its hundredth reward is over in practice long before anybody
     * gets round to marking it completed.
     */
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

    /** How many reward units are still there to be won. */
    public function availableCount(): int
    {
        return $this->poolEntries()
            ->where('status', PoolEntryStatus::Available)
            ->count();
    }

    /**
     * The campaign a qualifying purchase would be measured against.
     *
     * At most one may be active at a time - `CampaignService` holds that,
     * because MySQL has no partial unique index to say it with - so this
     * orders deterministically and takes one rather than trusting that only
     * one came back.
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
