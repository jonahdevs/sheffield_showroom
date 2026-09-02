<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PoolEntryStatus;
use App\Models\Reward;
use App\Models\RewardCampaign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RewardCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->subject();

        return $subject === null
            ? $this->user()->can('create', RewardCampaign::class)
            : $this->user()->can('update', $subject);
    }

    # A bare end date arrives as midnight, which is the *start* of that day - a
    # campaign ending on the 28th would stop as the 28th began. The start needs
    # no such help. A value already carrying a clock meant it, so it is left.
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

            'max_shuffles_per_customer' => ['required', 'integer', 'min:1', 'max:100'],

            'minimum_purchase_amount' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999.99'],
        ];

        $rules['rewards'] = ['present', 'array', 'max:20'];

        $rules['rewards.*.reward_id'] = [
            'required',
            'integer',
            Rule::exists('rewards', 'id'),
        ];

        # Still required after publication, where it is read only for a reward
        # being added. A kept attachment's quantity is inventory and the
        # incoming number is ignored - see `writeRewards`.
        $rules['rewards.*.quantity'] = ['required', 'integer', 'min:1', 'max:100000'];

        $rules['rewards.*.validity_days'] = ['nullable', 'integer', 'min:1', 'max:3650'];

        # Absent or empty is the common case: the reward qualifies against
        # any purchase.
        $rules['rewards.*.qualifying_product_ids'] = ['sometimes', 'array', 'max:50'];
        $rules['rewards.*.qualifying_product_ids.*'] = [
            'integer',
            Rule::exists('products', 'id'),
        ];

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('rewards')) {
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
                $this->refuseRemovingWonRewards($validator);
            },
        ];
    }

    # `campaign_rewards` is unique on `(campaign_id, reward_id)`; caught here so
    # the repeated row can be named instead of throwing.
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

    # Only rewards being *added* are refused. Refusing every retired reward
    # would lock every draft already holding one.
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
     * An attachment may be taken out of a campaign at any time - the units it
     * loses are inventory nobody holds. One with a claimed unit may never be:
     * a `shuffle_results` row points at that unit, so removing the attachment
     * would erase somebody's win. `reward_pool_entries` is `restrictOnDelete`
     * from the results, so the database would refuse it anyway - caught here
     * so the reward can be named instead of throwing.
     */
    private function refuseRemovingWonRewards(Validator $validator): void
    {
        $campaign = $this->subject();

        if ($campaign === null) {
            return;
        }

        $incoming = array_map('intval', array_filter(array_column($this->rewards(), 'reward_id')));

        $won = $campaign->rewards()
            ->with('reward.product')
            ->whereNotIn('reward_id', $incoming)
            ->whereHas(
                'poolEntries',
                fn (Builder $entries) => $entries->where('status', PoolEntryStatus::Claimed),
            )
            ->get();

        foreach ($won as $attachment) {
            $validator->errors()->add(
                'rewards',
                __(':name has already been won by a customer and cannot be taken out of the campaign. Void its remaining units instead.', [
                    'name' => $attachment->reward->readableName(),
                ]),
            );
        }
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
