<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

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
