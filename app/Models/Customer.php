<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerType;
use App\Policies\CustomerPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $legacy_id
 * @property CustomerType $type
 * @property string|null $name
 * @property string|null $company_name
 * @property string|null $segment
 * @property string $phone
 * @property string|null $email
 * @property string|null $id_number
 * @property string|null $street_address
 * @property string|null $area
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string $country
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[UsePolicy(CustomerPolicy::class)]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'name',
        'company_name',
        'segment',
        'phone',
        'email',
        'id_number',
        'street_address',
        'area',
        'city',
        'state',
        'postal_code',
        'country',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Visit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function displayName(): string
    {
        return $this->isCompany()
            ? (string) $this->company_name
            : (string) $this->name;
    }

    public function addressLine(): ?string
    {
        $parts = array_filter([
            $this->street_address,
            $this->area,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    public function isCompany(): bool
    {
        return $this->type === CustomerType::Company;
    }

    public function isIndividual(): bool
    {
        return $this->type === CustomerType::Individual;
    }

    # Digits identifying the subscriber once the country code and trunk
    # prefix are off the front. Nine covers Kenya and its neighbours.
    private const SUBSCRIBER_DIGITS = 9;

    /**
     * Phone is compared on the stripped subscriber tail - see `matchingPhone`.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $tail = self::subscriberTail($term);

        $query->where(function (Builder $inner) use ($like, $tail) {
            $inner->where('name', 'like', $like)
                ->orWhere('company_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('id_number', 'like', $like);

            if ($tail !== '') {
                $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";

                $inner->orWhereRaw(sprintf($stripped, 'phone').' like ?', ['%'.$tail.'%']);
            }
        });
    }

    private static function subscriberTail(string $term): string
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        return strlen($digits) > self::SUBSCRIBER_DIGITS
            ? substr($digits, -self::SUBSCRIBER_DIGITS)
            : $digits;
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function ofType(Builder $query, CustomerType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Compared on the stripped subscriber tail, not the string: `0722 000 111`
     * and `+254722000111` are one telephone.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function matchingPhone(Builder $query, string $phone): void
    {
        $tail = self::subscriberTail($phone);

        if ($tail === '') {
            # No one rather than everyone - the latter attaches the visit to
            # a stranger.
            $query->whereRaw('1 = 0');

            return;
        }

        $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";

        $query->whereRaw(sprintf($stripped, 'phone').' like ?', ['%'.$tail]);
    }
}
