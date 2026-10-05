<?php

namespace Modules\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShippingRangeStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'province_id'        => ['required', 'exists:provinces,id'],
            'city_id'            => ['nullable', 'exists:cities,id'],
            'cost'               => ['required', 'integer', 'min:0'],
            'min_order_amount'   => ['nullable', 'integer', 'min:0'],
            'max_order_amount'   => ['nullable', 'integer', 'min:0'],
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
