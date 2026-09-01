<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

            'visit_id' => [
                'nullable',
                Rule::exists('visits', 'id')->whereNull('deleted_at'),
            ],

            # `nullable`, not `present`: an absent key means "not this caller's
            # business" and leaves the pivot alone, while an empty array clears it.
            'product_ids' => ['nullable', 'array', 'max:50'],
            'product_ids.*' => [
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],

            'reference' => ['nullable', 'string', 'max:255'],

            'amount' => ['required', 'decimal:0,2', 'min:0', 'max:99999999.99'],

            'status' => ['required', Rule::enum(PurchaseStatus::class)],

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
