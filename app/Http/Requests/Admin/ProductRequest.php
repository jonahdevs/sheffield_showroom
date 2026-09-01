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

    # An empty SKU box is an uncoded product, not one coded `''` - left a string
    # it collides with the next uncoded product on the unique index.
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

            # Absent means "leave it alone", and the controller answers that
            # differently on create than on edit.
            'status' => ['nullable', Rule::enum(ProductStatus::class)],

            # `mimes` rather than `image`: `image` also accepts an SVG, which is
            # a script that happens to draw.
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],

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
