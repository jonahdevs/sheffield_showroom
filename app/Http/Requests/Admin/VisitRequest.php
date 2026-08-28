<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\VisitPurpose;
use App\Models\Customer;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Logging and correcting a visit.
 *
 * The form finds a customer or types one, so this validates both halves: an id
 * when somebody was picked off the list, and the details when they were
 * written in. `resolveCustomer()` is what turns either into a record.
 *
 * The date and the time arrive as two fields because that is how they are
 * entered; `visitedAt()` is what puts them back together for the column.
 */
class VisitRequest extends FormRequest
{
    /** Nobody sat with a customer for two days. */
    private const MAX_DURATION_MINUTES = 720;

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
        /* The customer's own details are only asked for when nobody was picked
           off the list. Picked, the record on file is what counts and the form
           is showing it back read-only. */
        $isNew = ! $this->filled('customer_id');
        $isCompany = $this->input('customer_type') === CustomerType::Company->value;

        return [
            /* `exists` rather than a bare integer: the combobox sends an id,
               and a soft-deleted customer must not be attachable to a new
               visit even though the id is still in the table. */
            'customer_id' => [
                'nullable',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],

            'customer_type' => [Rule::requiredIf($isNew), 'nullable', Rule::enum(CustomerType::class)],
            'customer_name' => [Rule::requiredIf($isNew && ! $isCompany), 'nullable', 'string', 'max:120'],
            'company_name' => [Rule::requiredIf($isNew && $isCompany), 'nullable', 'string', 'max:160'],
            /* Digits, spaces and the punctuation people actually write:
               +254 700 123 456, 0700-123-456, (020) 271 1000. */
            'phone' => [Rule::requiredIf($isNew), 'nullable', 'string', 'max:30', 'regex:/^[0-9+()\s-]+$/'],
            'email' => ['nullable', 'email', 'max:180'],

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

            'product_ids' => ['nullable', 'array', 'max:100'],
            'product_ids.*' => [
                'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * The customer this visit belongs to, adding them if they are new.
     *
     * Three ways in, in order of how sure each one is: the id the list sent,
     * the telephone number matched against everyone on file, and only then a
     * new record. The middle step is what stops a walk-in who came last month
     * being filed a second time because nobody thought to search first.
     */
    public function resolveCustomer(): Customer
    {
        $picked = $this->validated('customer_id');

        if ($picked !== null) {
            return Customer::query()->findOrFail($picked);
        }

        $phone = (string) $this->validated('phone');

        $existing = Customer::query()->matchingPhone($phone)->first();

        if ($existing !== null) {
            return $existing;
        }

        $type = CustomerType::from((string) $this->validated('customer_type'));

        $customer = new Customer([
            'type' => $type,
            'name' => $type === CustomerType::Individual ? $this->validated('customer_name') : null,
            'company_name' => $type === CustomerType::Company ? $this->validated('company_name') : null,
            'phone' => $phone,
            'email' => $this->validated('email'),
            'country' => 'Kenya',
        ]);

        $customer->created_by = $this->user()->id;
        $customer->save();

        return $customer;
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
     * The products shown, with duplicates dropped - the pivot is unique on the
     * pair, and a repeated id would fail on the way in rather than here.
     *
     * @return array<int, int>
     */
    public function productIds(): array
    {
        /** @var array<int, mixed> $ids */
        $ids = $this->validated('product_ids') ?? [];

        return array_values(array_unique(array_map(intval(...), $ids)));
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
            'product_ids' => 'products viewed',
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
