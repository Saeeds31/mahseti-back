<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductVariantUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'product_id' => ['sometimes', 'exists:products,id'],
            'sku'              => ['nullable', 'string', 'max:100'],
            'stock'            => ['nullable', 'integer', 'min:0'],
            'price'            => ['required', 'integer', 'min:0'],
            'values' => 'nullable|array',
            'values.*' => 'exists:attribute_values,id',
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
