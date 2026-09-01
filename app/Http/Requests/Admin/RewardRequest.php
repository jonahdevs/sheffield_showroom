<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Models\Reward;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

# The catalogue, not the campaign: quantity and the winner's real deadline live
# on `campaign_rewards`. `default_validity_days` is only what an attachment
# copies down, never read through at win time, so retuning it moves no deadline.
class RewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->subject();

        return $subject === null
            ? $this->user()->can('create', Reward::class)
            : $this->user()->can('update', $subject);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::enum(RewardType::class)],

            'product_id' => [
                Rule::requiredIf(fn (): bool => $this->chosenType()?->isProduct() === true),
                Rule::prohibitedIf(fn (): bool => $this->chosenType()?->isProduct() === false),
                'nullable',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],

            'value' => [
                'nullable',
                'required_with:value_unit',
                'decimal:0,2',
                'min:0',
                'max:99999999.99',
            ],

            'value_unit' => [
                'nullable',
                'required_with:value',
                Rule::enum(RewardValueUnit::class),
            ],

            'terms' => ['nullable', 'string', 'max:2000'],

            'default_validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],

            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['value', 'value_unit'])) {
                    return;
                }

                if ($this->input('value_unit') !== RewardValueUnit::Percentage->value) {
                    return;
                }

                if ((float) $this->input('value') > 100) {
                    $validator->errors()->add(
                        'value',
                        'A percentage reward cannot be worth more than 100%.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.Illuminate\Validation\Rules\Enum' => 'Choose one of the listed kinds of reward.',
            'product_id.required' => 'Choose the product this reward hands over.',
            'product_id.prohibited' => 'Only a product reward hands over a product.',
            'product_id.exists' => 'That product is no longer on the floor.',
            'value.required_with' => 'Say what the reward is worth, or clear the unit.',
            'value.decimal' => 'Use at most two decimal places.',
            'value_unit.required_with' => 'Say whether that figure is a percentage or shillings.',
            'value_unit.Illuminate\Validation\Rules\Enum' => 'Choose whether the figure is a percentage or shillings.',
            'default_validity_days.max' => 'A reward cannot be valid for more than ten years.',
        ];
    }

    private function chosenType(): ?RewardType
    {
        $type = $this->input('type');

        return is_string($type) ? RewardType::tryFrom($type) : null;
    }

    public function subject(): ?Reward
    {
        $reward = $this->route('reward');

        return $reward instanceof Reward ? $reward : null;
    }
}
