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
 * What was won. Permanent, and never deleted.
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
     * Whether staff may hand this over.
     *
     * Two questions, not one: the status has to allow it and the calendar has
     * to agree. A reward whose date has passed but which nothing has swept yet
     * is expired in every sense that matters, and reading only the status
     * would hand it over anyway.
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
     * Rewards matching what somebody typed into the search box.
     *
     * Two questions in one field, because they are asked at the same desk with
     * the same keystrokes: the code off a customer's phone, and the person who
     * won it. The customer half is delegated to `Customer::search()` rather
     * than written again here - that scope already knows to strip the spaces
     * and the country code out of a phone number before comparing it, so
     * typing 0712 finds a customer stored as +254712, and a second
     * hand-rolled `like` on `phone` would quietly fail to.
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
     * The rewards whose date has passed but which are still marked unredeemed,
     * which is what `rewards:expire` sweeps.
     *
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
