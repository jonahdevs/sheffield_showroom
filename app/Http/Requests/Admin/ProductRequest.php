<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->product();

        return $product === null
            ? $this->user()->can('create', Product::class)
            : $this->user()->can('update', $product);
    }

    /**
     * An empty SKU box is an uncoded product, not a product coded `''`. Left
     * as a string it would collide with the next one on the unique index.
     */
    protected function prepareForValidation(): void
    {
        $sku = $this->input('sku');

        if (is_string($sku) && trim($sku) === '') {
            $this->merge(['sku' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'sku' => [
                'nullable',
                'string',
                'max:60',
                Rule::unique('products', 'sku')->ignore($this->product()?->id),
            ],

            /* Optional so a caller that predates the column - or a form that
               only wants to fix a typo in a name - is not made to restate it.
               The controller decides what an absent status means, which is not
               the same answer on create as on edit. */
            'status' => ['nullable', Rule::enum(ProductStatus::class)],

            /* 4MB and the four formats a phone or a catalogue export
               produces. `image` alone would also accept an SVG, which is a
               script that happens to draw. */
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],

            /* Ticked on the edit form to drop the picture without adding a
               replacement. */
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.unique' => 'Another product already has that SKU.',
            'status.Illuminate\Validation\Rules\Enum' => 'Choose one of the listed statuses.',
            'image.mimes' => 'Use a JPG, PNG or WEBP image.',
            'image.max' => 'The image must be 4MB or smaller.',
        ];
    }

    public function product(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }
}
