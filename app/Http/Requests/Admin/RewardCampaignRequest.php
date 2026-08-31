<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Reward;
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
 * What arrives under `rewards` is a list of attachments, not descriptions. A
 * reward is written once in the catalogue and chosen here by its id, so this
 * form decides only how many, for how long, and what somebody must have bought
 * to be in the running - never what the thing is.
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

            /* The catalogue row being attached. What the reward *is* is no
               longer typed on this form - it is chosen, and everything
               describing it is read from `rewards`. */
            $rules['rewards.*.reward_id'] = [
                'required',
                'integer',
                Rule::exists('rewards', 'id'),
            ];

            /* A quantity of zero is a reward that exists on the form and not
               in the drawer, which is the shape of a promotion that quietly
               promises something it cannot hand over. */
            $rules['rewards.*.quantity'] = ['required', 'integer', 'min:1', 'max:100000'];

            $rules['rewards.*.validity_days'] = ['nullable', 'integer', 'min:1', 'max:3650'];

            /* What somebody must have bought to be in the running. Absent or
               empty is the common case and means any purchase qualifies - see
               `campaign_reward_product`. */
            $rules['rewards.*.qualifying_product_ids'] = ['sometimes', 'array', 'max:50'];
            $rules['rewards.*.qualifying_product_ids.*'] = [
                'integer',
                Rule::exists('products', 'id'),
            ];
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

                $this->refuseRepeatedRewards($validator);
                $this->refuseRetiredRewards($validator);
            },
        ];
    }

    /**
     * One row per reward.
     *
     * `campaign_rewards` holds a unique index on `(campaign_id, reward_id)`,
     * so a form naming the same reward twice would reach the database and
     * throw. Caught here instead, where it can be said in words and pointed at
     * the row that repeated it.
     */
    private function refuseRepeatedRewards(Validator $validator): void
    {
        $seen = [];

        foreach ($this->rewards() as $index => $reward) {
            $rewardId = $reward['reward_id'] ?? null;

            if ($rewardId === null) {
                continue;
            }

            if (isset($seen[$rewardId])) {
                $validator->errors()->add(
                    "rewards.{$index}.reward_id",
                    'This reward is already in the campaign. Change its quantity rather than adding it twice.',
                );

                continue;
            }

            $seen[$rewardId] = $index;
        }
    }

    /**
     * A retired reward may stay where it already is and go into nothing new.
     *
     * Checked against what the campaign already holds rather than refused
     * outright: `rewards.is_active` is switched off to stop a reward being
     * offered again, and a draft that has held it since before that must still
     * be saveable - otherwise retiring a reward would quietly lock every
     * campaign carrying it.
     */
    private function refuseRetiredRewards(Validator $validator): void
    {
        $held = $this->subject()?->rewards()->pluck('reward_id')->all() ?? [];

        $incoming = array_filter(array_column($this->rewards(), 'reward_id'));

        $added = array_diff($incoming, $held);

        if ($added === []) {
            return;
        }

        $retired = Reward::query()
            ->whereIn('id', $added)
            ->where('is_active', false)
            ->pluck('id')
            ->all();

        if ($retired === []) {
            return;
        }

        foreach ($this->rewards() as $index => $reward) {
            if (in_array($reward['reward_id'] ?? null, $retired, true)) {
                $validator->errors()->add(
                    "rewards.{$index}.reward_id",
                    'This reward has been retired and cannot be added to a campaign.',
                );
            }
        }
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
