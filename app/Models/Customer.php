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
 * @property string|null $industry
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
        'industry',
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
     * Every call they have made at the showroom.
     *
     * A removed visit is not one of them: `Visit` soft deletes, so the
     * relation carries its own scope and a count taken through here is a count
     * of what still stands.
     *
     * @return HasMany<Visit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /**
     * What to call them. A company is named by the company, not by whoever
     * from it walked in - that person is `name`, and they are who you ask for.
     */
    public function displayName(): string
    {
        return $this->isCompany()
            ? (string) $this->company_name
            : (string) $this->name;
    }

    /**
     * The address as one line, skipping whatever was left blank.
     */
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

    /**
     * How many digits of a number identify the subscriber, once the country
     * code and the trunk prefix are off the front. Nine covers Kenya and its
     * neighbours, which is who walks into this showroom.
     */
    private const SUBSCRIBER_DIGITS = 9;

    /**
     * Search across both names, the email, the ID number and the phone.
     *
     * People write a number down however they please and the record keeps
     * whatever shape it was given, so both sides are reduced to digits and
     * compared on their tail. That is what makes `0722 000 111`,
     * `0722000111` and `+254722000111` all find each other: strip the
     * punctuation and the last nine digits are the same number.
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

    /**
     * The digits of a number that identify the subscriber: everything after a
     * country code or a trunk prefix. A term shorter than that is used whole,
     * so a partial number still finds something.
     */
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
     * The customer reachable on this number, whichever way it was written.
     *
     * The number is the one thing a returning visitor gives the same way
     * twice, so it is what stops the visit form filing them a second time
     * under a slightly different spelling of their name. Compared on the
     * subscriber tail for the same reason `search()` is: `0722 000 111` and
     * `+254722000111` are one telephone.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function matchingPhone(Builder $query, string $phone): void
    {
        $tail = self::subscriberTail($phone);

        if ($tail === '') {
            /* Nothing to match on. Matched against no one rather than against
               everyone, which would attach the visit to a stranger. */
            $query->whereRaw('1 = 0');

            return;
        }

        $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";

        $query->whereRaw(sprintf($stripped, 'phone').' like ?', ['%'.$tail]);
    }
}
