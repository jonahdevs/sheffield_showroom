<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VisitorType;
use App\Policies\VisitPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $legacy_id
 * @property int|null $customer_id
 * @property string $visitor_type
 * @property string|null $visitor_name
 * @property string|null $visitor_phone
 * @property string|null $visitor_organisation
 * @property string|null $respondent
 * @property CarbonImmutable $visited_at
 * @property string $purpose
 * @property string $source
 * @property string|null $referred_by
 * @property string|null $department
 * @property CarbonImmutable|null $expected_follow_up_on
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[UsePolicy(VisitPolicy::class)]
class Visit extends Model
{
    /** @use HasFactory<VisitFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'visitor_type',
        'visitor_name',
        'visitor_phone',
        'visitor_organisation',
        'respondent',
        'visited_at',
        'purpose',
        'source',
        'referred_by',
        'department',
        'expected_follow_up_on',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'immutable_datetime',
            'expected_follow_up_on' => 'immutable_date',
        ];
    }

    /**
     * Null on every visit by somebody who was not buying - see `visitor_type`.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('quantity', 'interest_level');
    }

    /** Whether the caller was somebody buying, rather than everyone else at the door. */
    public function isCustomerVisit(): bool
    {
        return $this->visitor_type === VisitorType::Customer->value;
    }

    /** Who came in, wherever this visit keeps them. */
    public function visitorName(): string
    {
        return $this->customer?->displayName() ?? (string) $this->visitor_name;
    }

    /**
     * How many distinct customers a window holds, as a select expression. The door
     * log is not a customer list, so a plain `COUNT(DISTINCT customer_id)` would
     * count nothing for the cheque runners and the couriers - their `customer_id`
     * is null - but the `CASE` is kept explicit rather than leaning on that, so the
     * count still reads as the question it answers.
     */
    public static function customerCount(): string
    {
        return sprintf(
            "COUNT(DISTINCT CASE WHEN visits.visitor_type = '%s' THEN visits.customer_id END)",
            VisitorType::Customer->value,
        );
    }

    /**
     * The boundary `visits.view.own` draws.
     *
     * Qualified: `customers` and `products` each carry a `created_by` of their own,
     * so on any query that joins one - the dashboard's product panels - the bare
     * column is ambiguous and the whole query fails.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function loggedBy(Builder $query, User $user): void
    {
        $query->where('visits.created_by', $user->id);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';

        # Qualified for the same reason as `loggedBy`: `customers.notes` exists too.
        $query->where(fn (Builder $inner) => $inner
            ->whereHas('customer', fn (Builder $customer) => $customer->search($term))
            # Half the log has no customer to search - see `visitor_type`.
            ->orWhere('visits.visitor_name', 'like', $like)
            ->orWhere('visits.visitor_phone', 'like', $like)
            ->orWhere('visits.visitor_organisation', 'like', $like)
            ->orWhere('visits.respondent', 'like', $like)
            ->orWhere('visits.notes', 'like', $like));
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forPurpose(Builder $query, string $purpose): void
    {
        $query->where('purpose', $purpose);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forDepartment(Builder $query, string $department): void
    {
        $query->where('department', $department);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forVisitorType(Builder $query, VisitorType|string $visitor): void
    {
        $query->where(
            'visits.visitor_type',
            $visitor instanceof VisitorType ? $visitor->value : $visitor,
        );
    }
}
