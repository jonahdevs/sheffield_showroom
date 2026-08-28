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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property CustomerType $type
 * @property string|null $name
 * @property CarbonImmutable|null $date_of_birth
 * @property string|null $occupation
 * @property string|null $company_name
 * @property string|null $industry
 * @property string|null $contact_person
 * @property string|null $contact_person_position
 * @property string $phone
 * @property string|null $alternative_phone
 * @property string|null $email
 * @property string|null $address_line_1
 * @property string|null $address_line_2
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
        'date_of_birth',
        'occupation',
        'company_name',
        'industry',
        'contact_person',
        'contact_person_position',
        'phone',
        'alternative_phone',
        'email',
        'address_line_1',
        'address_line_2',
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
            'date_of_birth' => 'immutable_date',
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
     * What to call them. A company is named by the company; the contact person
     * is who you ask for, not who the customer is.
     */
    public function displayName(): string
    {
        return $this->isCompany()
            ? (string) $this->company_name
            : (string) $this->name;
    }

    /**
     * The line under the name: what an individual does, or who to ask for at
     * a company. Null when neither was recorded.
     */
    public function subtitle(): ?string
    {
        if ($this->isCompany()) {
            return $this->contact_person === null
                ? $this->industry
                : trim($this->contact_person.($this->contact_person_position === null
                    ? ''
                    : ' - '.$this->contact_person_position));
        }

        return $this->occupation;
    }

    /**
     * The address as one line, skipping whatever was left blank.
     */
    public function addressLine(): ?string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
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
     * Search across the two names, the contact person and both numbers.
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
                ->orWhere('contact_person', 'like', $like)
                ->orWhere('email', 'like', $like);

            if ($tail !== '') {
                $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";

                $inner->orWhereRaw(sprintf($stripped, 'phone').' like ?', ['%'.$tail.'%'])
                    ->orWhereRaw(sprintf($stripped, 'alternative_phone').' like ?', ['%'.$tail.'%']);
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

        $query->where(fn (Builder $inner) => $inner
            ->whereRaw(sprintf($stripped, 'phone').' like ?', ['%'.$tail])
            ->orWhereRaw(sprintf($stripped, 'alternative_phone').' like ?', ['%'.$tail]));
    }
}
