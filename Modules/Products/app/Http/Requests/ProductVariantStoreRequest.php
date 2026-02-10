<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductVariantStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [

            'variants' => 'required|array|min:1',
            'variants.*.sku' => 'nullable|string|max:100',
            'variants.*.stock' => 'nullable|integer|min:0',
            'variants.*.price' => 'required|integer|min:0',
            'variants.*.values' => 'required|array|min:1',
            'variants.*.values.*' => 'exists:attribute_values,id',
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
