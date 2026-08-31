<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Models\RewardCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A campaign and the rewards inside it, on one form.
 *
 * They arrive together because they are one decision: a promotion is its dates
 * and its drawer, and asking somebody to save a campaign and then go and fill
 * it would let them publish an empty one by forgetting.
 *
 * The important rule here is what happens after publication. A published
 * campaign's quantities are controlled inventory - people have been told there
 * are twenty discounts - so the rewards are only accepted while the campaign
 * is still a draft. Everything else about it stays editable: a name, a
 * description and an end date are administration, not odds.
 */
class RewardCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->subject();

        return $subject === null
            ? $this->user()->can('create', RewardCampaign::class)
            : $this->user()->can('update', $subject);
    }

    /**
     * Closes the end date at the end of its day.
     *
     * The form asks for a day, because a promotion runs over days rather than
     * to a minute. Taken literally that day arrives as midnight, which is the
     * *start* of it - so a campaign set to end on the 28th would stop the
     * moment the 28th began, and the showroom would spend that day turning
     * people away from a promotion its own poster says is running.
     *
     * The start needs no such help: midnight on the first day is exactly when
     * it should open.
     *
     * Only a bare date is touched. A value that already carries a clock came
     * from somewhere that meant it, and is left alone.
     */
    protected function prepareForValidation(): void
    {
        $ends = $this->input('ends_at');

        if (is_string($ends) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends) === 1) {
            $this->merge(['ends_at' => $ends.' 23:59:59']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],

            /* At least one. A campaign that hands out nothing per customer is
               a campaign nobody can win. */
            'max_shuffles_per_customer' => ['required', 'integer', 'min:1', 'max:100'],

            'minimum_purchase_amount' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999.99'],
        ];

        if ($this->editsRewards()) {
            $rules['rewards'] = ['present', 'array', 'max:20'];
            $rules['rewards.*.name'] = ['required', 'string', 'max:150'];
            $rules['rewards.*.description'] = ['nullable', 'string', 'max:2000'];
            $rules['rewards.*.type'] = ['required', Rule::enum(RewardType::class)];

            /* A quantity of zero is a reward that exists on the form and not
               in the drawer, which is the shape of a promotion that quietly
               promises something it cannot hand over. */
            $rules['rewards.*.quantity'] = ['required', 'integer', 'min:1', 'max:100000'];

            $rules['rewards.*.value'] = ['nullable', 'decimal:0,2', 'min:0', 'max:99999999.99'];
            $rules['rewards.*.value_unit'] = ['nullable', Rule::enum(RewardValueUnit::class)];
            $rules['rewards.*.validity_days'] = ['nullable', 'integer', 'min:1', 'max:3650'];
            $rules['rewards.*.terms'] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->editsRewards() || $validator->errors()->has('rewards')) {
                    return;
                }

                if ($this->rewards() === []) {
                    $validator->errors()->add(
                        'rewards',
                        'A campaign needs at least one reward before anybody can win anything.',
                    );

                    return;
                }

                /* A number with no unit reads as nothing: "10" is not ten per
                   cent and not ten shillings. Either both or neither. */
                foreach ($this->rewards() as $index => $reward) {
                    $hasValue = ($reward['value'] ?? null) !== null;
                    $hasUnit = ($reward['value_unit'] ?? null) !== null;

                    if ($hasValue !== $hasUnit) {
                        $validator->errors()->add(
                            "rewards.{$index}.value_unit",
                            'Say whether the figure is a percentage or an amount.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * Whether the reward definitions on this request are to be read at all.
     *
     * Only while the campaign is a draft. Afterwards the pool has been written
     * and the quantities are inventory, so anything arriving under `rewards`
     * is a stale form rather than an instruction - it is dropped rather than
     * refused, the same way the profile screen drops an email.
     */
    public function editsRewards(): bool
    {
        return ! ($this->subject()?->status->isPublished() ?? false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rewards(): array
    {
        return array_values((array) $this->input('rewards', []));
    }

    public function subject(): ?RewardCampaign
    {
        $campaign = $this->route('campaign');

        return $campaign instanceof RewardCampaign ? $campaign : null;
    }
}
