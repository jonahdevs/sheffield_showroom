<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property int $customer_id
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

    /**
     * The boundary `visits.view.own` draws.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function loggedBy(Builder $query, User $user): void
    {
        $query->where('created_by', $user->id);
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

        $query->where(fn (Builder $inner) => $inner
            ->whereHas('customer', fn (Builder $customer) => $customer->search($term))
            ->orWhere('respondent', 'like', $like)
            ->orWhere('notes', 'like', $like));
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
}
