<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderItemUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'order_id'        => ['sometimes', 'exists:orders,id'],
            'product_id'      => ['sometimes', 'exists:products,id'],
            'product_variant_id'        => ['sometimes', 'exists:product_variants,id'],
            'quantity'        => ['sometimes', 'integer', 'min:1'],
            'price'           => ['sometimes', 'integer', 'min:0'],
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
