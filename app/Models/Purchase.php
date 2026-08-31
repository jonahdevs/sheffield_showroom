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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What somebody bought, and for how much.
 *
 * Narrow on purpose - an eligibility record rather than a ledger. See the
 * migration for why there are no line items here.
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
        'product_id',
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
            /* `decimal:2` rather than a float. This number is compared against
               a campaign threshold, and a purchase falling the wrong side of
               one by a rounding error is a customer told they did not qualify
               when they did. */
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
     * The main item bought, where anybody recorded one.
     *
     * Read by rewards that name the products qualifying for them - buy the
     * oven, win the tray. Null on most rows, and a campaign that pairs nothing
     * never asks.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The turn this purchase earned, if it has been given one yet.
     *
     * `hasOne` rather than `hasMany` because the unique index on
     * `shuffle_sessions.purchase_id` makes a second one impossible - one sale,
     * one turn.
     *
     * @return HasOne<ShuffleSession, $this>
     */
    public function shuffleSession(): HasOne
    {
        return $this->hasOne(ShuffleSession::class);
    }

    /**
     * Only the purchases that can earn a shuffle. A sale still being settled
     * is deliberately not one of them: a reward handed out against a payment
     * that later falls through cannot be taken back.
     *
     * @param  Builder<Purchase>  $query
     */
    #[Scope]
    protected function qualifying(Builder $query): void
    {
        $query->where('status', PurchaseStatus::Completed);
    }
}
