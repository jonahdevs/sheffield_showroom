<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShuffleSessionStatus;
use App\Policies\ShuffleSessionPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\ShuffleSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One customer's single turn.
 *
 * Addressed publicly by `token` and never by id, so the QR code carries
 * nothing anybody could count upwards from.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $customer_id
 * @property int|null $visit_id
 * @property int|null $purchase_id
 * @property string $token
 * @property CarbonImmutable|null $expires_at
 * @property ShuffleSessionStatus $status
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UsePolicy(ShuffleSessionPolicy::class)]
class ShuffleSession extends Model
{
    /** @use HasFactory<ShuffleSessionFactory> */
    use HasFactory;

    /**
     * The token is not fillable. It is minted once by
     * `ShuffleSessionService` from a cryptographic source, and a token that
     * could arrive on a request is a token somebody could choose.
     */
    protected $fillable = [
        'campaign_id',
        'customer_id',
        'visit_id',
        'purchase_id',
        'expires_at',
        'status',
    ];

    /**
     * Never serialised. The whole security of the public flow is that this
     * string is known only to the person holding the QR code, and a page prop
     * carrying it would put every session's token in the browser of anybody
     * who could see the list.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'status' => ShuffleSessionStatus::class,
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Visit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /**
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<ShuffleResult, $this>
     */
    public function result(): HasOne
    {
        return $this->hasOne(ShuffleResult::class);
    }

    /** Whether the calendar has run out on this turn. */
    public function hasExpired(?CarbonImmutable $at = null): bool
    {
        return $this->expires_at !== null
            && ($at ?? CarbonImmutable::now())->greaterThan($this->expires_at);
    }

    /**
     * Whether this turn can still be taken.
     *
     * Read rather than trusted at the point of use: the authoritative version
     * of this question is asked again inside the claiming transaction, where
     * the row is locked. This one is for deciding what to draw on the screen.
     */
    public function isShuffleable(?CarbonImmutable $at = null): bool
    {
        return $this->status === ShuffleSessionStatus::Pending && ! $this->hasExpired($at);
    }

    /**
     * The turns that have run out but are still marked pending, which is what
     * `rewards:expire` sweeps.
     *
     * @param  Builder<ShuffleSession>  $query
     */
    #[Scope]
    protected function lapsed(Builder $query, ?CarbonImmutable $at = null): void
    {
        $query->where('status', ShuffleSessionStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $at ?? CarbonImmutable::now());
    }
}
