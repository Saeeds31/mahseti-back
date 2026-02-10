<?php

namespace Modules\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShippingRangeUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'shipping_method_id' => ['sometimes', 'exists:shipping_methods,id'],
            'province_id'        => ['sometimes', 'exists:provinces,id'],
            'city_id'            => ['sometimes', 'nullable', 'exists:cities,id'],
            'cost'               => ['sometimes', 'integer', 'min:0'],
            'min_order_amount'   => ['sometimes', 'integer', 'min:0'],
            'max_order_amount'   => ['sometimes', 'integer', 'min:0'],
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
