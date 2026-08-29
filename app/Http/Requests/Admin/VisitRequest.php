<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\InterestLevel;
use App\Enums\VisitPurpose;
use App\Models\Customer;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Logging and correcting a visit.
 *
 * The form types the customer's name and offers whoever already answers to it,
 * so the details always arrive and the id only says whether they describe a
 * record already on file. `resolveCustomer()` is what turns the pair into one.
 *
 * The date and the time arrive as two fields because that is how they are
 * entered; `visitedAt()` is what puts them back together for the column.
 */
class VisitRequest extends FormRequest
{
    /** Nobody sat with a customer for two days. */
    private const MAX_DURATION_MINUTES = 720;

    /** Past this it is a typo, not an order the showroom floor took. */
    private const MAX_QUANTITY = 9999;

    public function authorize(): bool
    {
        $visit = $this->visit();

        return $visit === null
            ? $this->user()->can('create', Visit::class)
            : $this->user()->can('update', $visit);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /* Asked for either way. The form no longer has a picked mode and a
           typed one - the name is typed, and the id only says whether the
           details on screen belong to a record already or to somebody about
           to become one. */
        $isCompany = $this->input('customer_type') === CustomerType::Company->value;

        return [
            /* `exists` rather than a bare integer: the suggestions send an id,
               and a soft-deleted customer must not be attachable to a new
               visit even though the id is still in the table. */
            'customer_id' => [
                'nullable',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],

            'customer_type' => ['required', Rule::enum(CustomerType::class)],
            /* Asked of both kinds. A company does not walk into a showroom;
               somebody from it does, and they are who the counter dealt
               with. */
            'customer_name' => ['required', 'string', 'max:120'],
            /* Digits, spaces and the punctuation people actually write:
               +254 700 123 456, 0700-123-456, (020) 271 1000. */
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\s-]+$/'],
            'email' => ['nullable', 'email', 'max:180'],
            'id_number' => ['nullable', 'string', 'max:30'],

            'company_name' => [Rule::requiredIf($isCompany), 'nullable', 'string', 'max:160'],
            'industry' => ['nullable', 'string', 'max:120'],

            'visited_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'visited_time' => ['required', 'date_format:H:i'],

            'purpose' => ['required', Rule::enum(VisitPurpose::class)],
            'source' => ['required', Rule::enum(CustomerSource::class)],

            /* Who took the visit. The form pre-fills whoever is signed in, so
               requiring it costs the common case nothing and the floor gets a
               name against every call. */
            'respondent' => ['required', 'string', 'max:120'],

            /* You chase somebody after seeing them, never before. */
            'expected_follow_up_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:visited_on'],

            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DURATION_MINUTES],

            'notes' => ['nullable', 'string', 'max:2000'],

            'products' => ['nullable', 'array', 'max:100'],
            'products.*.id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            /* Nobody enquires after none of something, and a showroom
               order of ten thousand sheets is a typo rather than a sale. */
            'products.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            /* Required rather than defaulted here: the form puts a level
               against every row it adds, so one arriving without it is a form
               that has gone wrong rather than a salesperson who declined to
               say. */
            'products.*.interest_level' => ['required', Rule::enum(InterestLevel::class)],
        ];
    }

    /**
     * The customer this visit belongs to, adding or correcting them as needed.
     *
     * Three ways in, in order of how sure each one is: the id the suggestions
     * sent, the telephone number matched against everyone on file, and only
     * then a new record. The middle step is what stops a walk-in who came last
     * month being filed a second time because nobody thought to search first.
     *
     * Picked off the suggestions, the fields stay editable and what comes back
     * is written to the record: a wrong number noticed at the counter is
     * corrected where it was noticed. Only by somebody who may edit customers
     * though - the form shows the details read-only to anybody else, and an
     * edit that arrives regardless is not one they were offered.
     */
    public function resolveCustomer(): Customer
    {
        $picked = $this->validated('customer_id');

        if ($picked !== null) {
            $customer = Customer::query()->findOrFail($picked);

            if ($this->user()->can('update', $customer)) {
                $customer->fill($this->customerAttributes())->save();
            }

            return $customer;
        }

        $existing = Customer::query()
            ->matchingPhone((string) $this->validated('phone'))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $customer = new Customer([
            ...$this->customerAttributes(),
            'country' => 'Kenya',
        ]);

        $customer->created_by = $this->user()->id;
        $customer->save();

        return $customer;
    }

    /**
     * The customer half of the form, as the columns keep it.
     *
     * The person is recorded either way; the company is what the type adds.
     * A form should not quietly rewrite a field it never showed, so the
     * business half is left out entirely for an individual rather than
     * nulled.
     *
     * @return array<string, mixed>
     */
    private function customerAttributes(): array
    {
        $type = CustomerType::from((string) $this->validated('customer_type'));

        /* The business half only for a company. Left out rather than nulled
           for an individual, so a record that already carries an employer
           entered under Customers is not cleared by a visit write-up that
           never showed the field. */
        $business = $type === CustomerType::Company
            ? [
                'company_name' => $this->validated('company_name'),
                'industry' => $this->validated('industry'),
            ]
            : [];

        return [
            'type' => $type,
            'name' => $this->validated('customer_name'),
            'phone' => (string) $this->validated('phone'),
            'email' => $this->validated('email'),
            'id_number' => $this->validated('id_number'),
            ...$business,
        ];
    }

    /**
     * The visit as the column stores it.
     *
     * Guarded against a future moment even after the two fields pass their own
     * rules: `visited_on` can be today and `visited_time` an hour from now,
     * and neither rule alone can see that.
     */
    public function visitedAt(): CarbonImmutable
    {
        $moment = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $this->string('visited_on').' '.$this->string('visited_time'),
        );

        return $moment->isFuture() ? CarbonImmutable::now() : $moment;
    }

    /**
     * The products shown and the interest against each, as `sync()` wants it.
     *
     * Keyed by id, which drops duplicates on the way: the pivot is unique on
     * the pair, and a product listed twice would fail on the way in rather
     * than here. The last mention of a repeated product wins, which is the one
     * whose level the person was looking at.
     *
     * @return array<int, array{quantity: int, interest_level: string}>
     */
    public function productSync(): array
    {
        /** @var array<int, array{id: mixed, quantity: mixed, interest_level: string}> $rows */
        $rows = $this->validated('products') ?? [];

        $sync = [];

        foreach ($rows as $row) {
            $sync[(int) $row['id']] = [
                'quantity' => (int) $row['quantity'],
                'interest_level' => $row['interest_level'],
            ];
        }

        return $sync;
    }

    /**
     * What belongs on the visit itself, without the customer half of the form.
     *
     * @return array<string, mixed>
     */
    public function visitAttributes(): array
    {
        return $this->safe()->only([
            'purpose',
            'source',
            'respondent',
            'expected_follow_up_on',
            'duration_minutes',
            'notes',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'customer_id' => 'customer',
            'customer_type' => 'customer type',
            'customer_name' => 'customer name',
            'company_name' => 'company or organisation',
            'phone' => 'phone number',
            'respondent' => 'assigned staff',
            'visited_on' => 'visit date',
            'visited_time' => 'visit time',
            'expected_follow_up_on' => 'expected follow-up',
            'duration_minutes' => 'duration',
            'products' => 'products viewed',
            'products.*.quantity' => 'quantity',
            'products.*.interest_level' => 'interest level',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.exists' => 'That customer is no longer on file.',
            'phone.required' => 'A phone number is what tells one customer from another.',
            'phone.regex' => 'Use digits, spaces, brackets, + and - only.',
            'respondent.required' => 'Say who took the visit.',
            'visited_on.before_or_equal' => 'A visit cannot be logged for a future date.',
            'expected_follow_up_on.after_or_equal' => 'The follow-up cannot be before the visit.',
            'duration_minutes.max' => 'That is longer than a working day. Check the number.',
        ];
    }

    public function visit(): ?Visit
    {
        $visit = $this->route('visit');

        return $visit instanceof Visit ? $visit : null;
    }
}
