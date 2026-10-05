<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderItemStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'order_id'        => ['required', 'exists:orders,id'],
            'product_id'      => ['required', 'exists:products,id'],
            'product_variant_id'        => ['required', 'exists:product_variants,id'],
            'quantity'        => ['required', 'integer', 'min:1'],
            'price'           => ['required', 'integer', 'min:0'],
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
