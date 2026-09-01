<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PoolEntryStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RewardPoolEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * `Claimed` is one-way - a won unit never returns to the pool. Use `Void` to
 * take unwon units off the table; reporting counts void as loaded, which is
 * what makes `loaded = available + claimed + void` reconcile.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $campaign_reward_id
 * @property PoolEntryStatus $status
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class RewardPoolEntry extends Model
{
    /** @use HasFactory<RewardPoolEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'campaign_reward_id',
        'status',
        'claimed_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => PoolEntryStatus::class,
            'claimed_at' => 'immutable_datetime',
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
     * @return BelongsTo<CampaignReward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(CampaignReward::class, 'campaign_reward_id');
    }

    /**
     * `hasOne` because the unique index on
     * `shuffle_results.reward_pool_entry_id` makes a second impossible. That
     * index is the backstop under the row lock in `ShuffleRewardService`.
     *
     * @return HasOne<ShuffleResult, $this>
     */
    public function result(): HasOne
    {
        return $this->hasOne(ShuffleResult::class);
    }

    /**
     * @param  Builder<RewardPoolEntry>  $query
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->where('status', PoolEntryStatus::Available);
    }
}
