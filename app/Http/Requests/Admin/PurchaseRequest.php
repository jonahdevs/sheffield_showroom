<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Recording what somebody spent.
 *
 * The amount is the only field here that decides anything: a reward campaign
 * reads it against a threshold, so a typo is the difference between a customer
 * being handed a turn and being turned away at the counter. It is bounded on
 * both sides for that reason - a negative sale is not a sale, and a figure
 * wider than the column would be truncated rather than refused.
 */
class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->subject();

        return $subject === null
            ? $this->user()->can('create', Purchase::class)
            : $this->user()->can('update', $subject);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],

            /* The call it happened on, where there was one. Checked against
               the customer below rather than here, because "this visit exists"
               and "this visit is this customer's" are different questions. */
            'visit_id' => [
                'nullable',
                Rule::exists('visits', 'id')->whereNull('deleted_at'),
            ],

            /* The main item bought, where anybody recorded one. Read only by
               rewards paired to a product - buy the oven, win the tray - so
               most sales leave it empty and nothing asks. */
            'product_id' => [
                'nullable',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],

            'reference' => ['nullable', 'string', 'max:255'],

            /* Two decimal places, matching the column. `decimal:0,2` rather
               than `numeric` so a figure with three of them is refused here
               rather than rounded silently on the way in. */
            'amount' => ['required', 'decimal:0,2', 'min:0', 'max:99999999.99'],

            'status' => ['required', Rule::enum(PurchaseStatus::class)],

            /* Not in the future. A sale that has not happened cannot earn a
               reward, and a mistyped year is the usual way one arrives. */
            'purchased_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['customer_id', 'visit_id'])) {
                    return;
                }

                $visitId = $this->input('visit_id');

                if ($visitId === null) {
                    return;
                }

                /* A purchase filed against somebody else's visit would tie the
                   sale, the visit and any reward won on it to two different
                   people. */
                $belongs = Visit::query()
                    ->whereKey($visitId)
                    ->where('customer_id', $this->integer('customer_id'))
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add(
                        'visit_id',
                        'That visit belongs to a different customer.',
                    );
                }
            },
        ];
    }

    public function subject(): ?Purchase
    {
        $purchase = $this->route('purchase');

        return $purchase instanceof Purchase ? $purchase : null;
    }
}
