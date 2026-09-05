<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\InterestLevel;
use App\Enums\VisitorType;
use App\Models\Customer;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VisitRequest extends FormRequest
{
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
        $isCustomer = $this->isCustomerVisit();
        $isCompany = $isCustomer && $this->input('customer_type') === CustomerType::Company->value;

        return [
            # `Rule::in` over `Rule::enum`: the column is free text holding these
            # values, so it is checked without being cast.
            'visitor_type' => ['required', Rule::in(VisitorType::values())],

            # Asked only of somebody buying: whether you buy for yourself or for a
            # firm means nothing about a courier. The form asks both as one question
            # and splits its answer into these two fields.
            'customer_type' => [
                Rule::requiredIf($isCustomer),
                Rule::prohibitedIf(! $isCustomer),
                'nullable',
                Rule::enum(CustomerType::class),
            ],

            # A soft-deleted customer keeps its id, so `exists` must exclude them.
            # Refused outright from anybody else: only a customer has a record to
            # attach to, and a stray id left on a form since switched to Courier
            # would file that call against a customer who was never there.
            'customer_id' => [
                Rule::prohibitedIf(! $isCustomer),
                'nullable',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],

            'visitor_name' => ['required', 'string', 'max:120'],

            # Required of a customer, who is recognised by it next time. Reception
            # often has no number for a courier, and a visit that will not save
            # without one is a visit that goes unlogged.
            'phone' => [
                Rule::requiredIf($isCustomer),
                'nullable',
                'string',
                'max:30',
                'regex:/^[0-9+()\s-]+$/',
            ],

            # Only a customer has a record to keep these on, so they are refused
            # from anybody else rather than accepted and silently dropped.
            'email' => [Rule::prohibitedIf(! $isCustomer), 'nullable', 'email', 'max:180'],
            'id_number' => [Rule::prohibitedIf(! $isCustomer), 'nullable', 'string', 'max:30'],

            # The firm behind them: the customer's own company for somebody buying,
            # and who sent them for everybody else.
            'organisation' => [Rule::requiredIf($isCompany), 'nullable', 'string', 'max:160'],

            'segment' => [Rule::prohibitedIf(! $isCustomer), 'nullable', 'string', 'max:120'],

            'visited_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'visited_time' => ['required', 'date_format:H:i'],

            'purpose' => ['required', 'string', 'max:120'],
            'source' => ['required', 'string', 'max:120'],
            'department' => ['required', 'string', 'max:120'],

            # A referral is still filed under "Referral" - this names who made
            # it, so it is required there and refused everywhere else rather
            # than folded into `source` as free text.
            'referred_by' => [
                Rule::requiredIf(fn (): bool => $this->isReferral()),
                Rule::prohibitedIf(fn (): bool => ! $this->isReferral()),
                'nullable',
                'string',
                'max:120',
            ],

            'respondent' => ['required', 'string', 'max:120'],

            'expected_follow_up_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:visited_on'],

            'notes' => ['nullable', 'string', 'max:2000'],

            'products' => ['nullable', 'array', 'max:100'],
            'products.*.id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'products.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'products.*.interest_level' => ['required', Rule::enum(InterestLevel::class)],
        ];
    }

    /**
     * Id, then phone match, then a new record. The phone step is what stops a
     * returning walk-in being filed twice; the `can('update')` gate is what stops a
     * read-only form quietly rewriting the customer it was only shown.
     *
     * Null for everybody who was not buying: they get no record and are not looked
     * for in the book either, so a courier logged against the number on a
     * customer's file cannot be attached to them. `visitAttributes()` writes them
     * onto the visit instead.
     */
    public function resolveCustomer(): ?Customer
    {
        if (! $this->isCustomerVisit()) {
            return null;
        }

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
     * @return array<string, mixed>
     */
    private function customerAttributes(): array
    {
        $type = CustomerType::from((string) $this->validated('customer_type'));

        # Left out rather than nulled for an individual: a visit write-up must
        # not clear an employer the Customers screen entered but never showed.
        $business = $type === CustomerType::Company
            ? [
                'company_name' => $this->validated('organisation'),
                'segment' => $this->validated('segment'),
            ]
            : [];

        return [
            'type' => $type,
            'name' => $this->validated('visitor_name'),
            'phone' => (string) $this->validated('phone'),
            'email' => $this->validated('email'),
            'id_number' => $this->validated('id_number'),
            ...$business,
        ];
    }

    # Still guarded against the future after both fields pass: `visited_on` may
    # be today and `visited_time` an hour off, and neither rule alone sees that.
    public function visitedAt(): CarbonImmutable
    {
        $moment = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $this->string('visited_on').' '.$this->string('visited_time'),
        );

        return $moment->isFuture() ? CarbonImmutable::now() : $moment;
    }

    /**
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
     * @return array<string, mixed>
     */
    public function visitAttributes(): array
    {
        $isCustomer = $this->isCustomerVisit();

        return [
            ...$this->safe()->only([
                'purpose',
                'source',
                'department',
                'respondent',
                'expected_follow_up_on',
                'notes',
            ]),
            # Written unconditionally: `prohibited` leaves the key out of
            # `validated()`, so a visit moved off Referral would otherwise keep
            # the name of whoever referred it.
            'referred_by' => $this->isReferral()
                ? $this->validated('referred_by')
                : null,

            'visitor_type' => $this->validated('visitor_type'),

            # Written unconditionally, and nulled for a customer, for the same
            # reason. A visit moved onto a customer would otherwise keep the
            # visitor it was filed under, leaving the row with two answers to
            # "who came in" and `visitorName()` reading the wrong one.
            'visitor_name' => $isCustomer ? null : $this->validated('visitor_name'),
            'visitor_phone' => $isCustomer ? null : $this->validated('phone'),
            'visitor_organisation' => $isCustomer ? null : $this->validated('organisation'),
        ];
    }

    public function isCustomerVisit(): bool
    {
        return $this->input('visitor_type') === VisitorType::Customer->value;
    }

    private function isReferral(): bool
    {
        return $this->input('source') === CustomerSource::Referral->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'customer_id' => 'customer',
            'customer_type' => 'customer type',
            'visitor_type' => 'visitor type',
            'visitor_name' => 'full name',
            'organisation' => 'company or organisation',
            'phone' => 'phone number',
            'visited_on' => 'visit date',
            'visited_time' => 'visit time',
            'expected_follow_up_on' => 'expected follow-up',
            'referred_by' => 'referrer',
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
            'referred_by.required' => 'Say who referred them.',
            'referred_by.prohibited' => 'Only a referral names who sent them.',
            'visited_on.before_or_equal' => 'A visit cannot be logged for a future date.',
            'expected_follow_up_on.after_or_equal' => 'The follow-up cannot be before the visit.',
        ];
    }

    public function visit(): ?Visit
    {
        $visit = $this->route('visit');

        return $visit instanceof Visit ? $visit : null;
    }
}
