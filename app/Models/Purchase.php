<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Policies\PurchasePolicy;
use Carbon\CarbonImmutable;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An eligibility record rather than a ledger - no line items, deliberately.
 *
 * @property int $id
 * @property int $customer_id
 * @property int|null $visit_id
 * @property string|null $reference
 * @property string $amount
 * @property PurchaseStatus $status
 * @property CarbonImmutable $purchased_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[UsePolicy(PurchasePolicy::class)]
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'visit_id',
        'reference',
        'amount',
        'status',
        'purchased_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            # `decimal:2`, not a float: this is compared against a campaign
            # threshold, and a rounding error refuses a customer who qualified.
            'amount' => 'decimal:2',
            'status' => PurchaseStatus::class,
            'purchased_at' => 'immutable_datetime',
        ];
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
     * `withTrashed()` on purpose: withdrawing a product must not make a
     * historical sale forget what it was for. The draw is the opposite -
     * `CampaignReward::qualifyingProducts()` keeps the default scope, so a
     * pairing to a withdrawn product stops qualifying anybody.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'purchase_product')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * `hasOne` because the unique index on `shuffle_sessions.purchase_id`
     * makes a second impossible - one sale, one turn.
     *
     * @return HasOne<ShuffleSession, $this>
     */
    public function shuffleSession(): HasOne
    {
        return $this->hasOne(ShuffleSession::class);
    }

    /**
     * Completed only: a reward handed out against a payment that later falls
     * through cannot be taken back.
     *
     * @param  Builder<Purchase>  $query
     */
    #[Scope]
    protected function qualifying(Builder $query): void
    {
        $query->where('status', PurchaseStatus::Completed);
    }
}
