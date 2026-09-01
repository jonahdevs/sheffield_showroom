<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RewardResultStatus;
use App\Policies\ShuffleResultPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\ShuffleResultFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Never deleted. `expires_at` is stamped at win time from `campaign_rewards.validity_days`
 * and never recomputed, so editing a definition cannot move a deadline
 * somebody already has.
 *
 * @property int $id
 * @property int $shuffle_session_id
 * @property int $reward_pool_entry_id
 * @property string $code
 * @property CarbonImmutable $won_at
 * @property CarbonImmutable|null $expires_at
 * @property RewardResultStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UsePolicy(ShuffleResultPolicy::class)]
class ShuffleResult extends Model
{
    /** @use HasFactory<ShuffleResultFactory> */
    use HasFactory;

    protected $fillable = [
        'shuffle_session_id',
        'reward_pool_entry_id',
        'code',
        'won_at',
        'expires_at',
        'status',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'won_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'status' => RewardResultStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ShuffleSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ShuffleSession::class, 'shuffle_session_id');
    }

    /**
     * @return BelongsTo<RewardPoolEntry, $this>
     */
    public function poolEntry(): BelongsTo
    {
        return $this->belongsTo(RewardPoolEntry::class, 'reward_pool_entry_id');
    }

    /**
     * @return HasOne<RewardRedemption, $this>
     */
    public function redemption(): HasOne
    {
        return $this->hasOne(RewardRedemption::class);
    }

    /**
     * Reads the date as well as the status: a lapsed reward nothing has swept
     * yet is expired, and checking only the status would hand it over.
     */
    public function isRedeemable(?CarbonImmutable $at = null): bool
    {
        if (! $this->status->isRedeemable()) {
            return false;
        }

        return $this->expires_at === null
            || ($at ?? CarbonImmutable::now())->lessThanOrEqualTo($this->expires_at);
    }

    /**
     * Delegates the customer half to `Customer::search()`: a hand-rolled
     * `like` on `phone` would not strip the country code, so typing 0712
     * would quietly fail to find a customer stored as +254712.
     *
     * @param  Builder<ShuffleResult>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $query->where(fn (Builder $inner) => $inner
            ->where('code', 'like', '%'.$term.'%')
            ->orWhereHas(
                'session.customer',
                fn (Builder $customer) => $customer->search($term),
            ));
    }

    /**
     * @param  Builder<ShuffleResult>  $query
     */
    #[Scope]
    protected function lapsed(Builder $query, ?CarbonImmutable $at = null): void
    {
        $query->where('status', RewardResultStatus::Unredeemed)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $at ?? CarbonImmutable::now());
    }
}
