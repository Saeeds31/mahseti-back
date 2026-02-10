<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'user_id'           => ['sometimes', 'exists:users,id'],
            'address_id'        => ['sometimes', 'exists:addresses,id'],
            'shipping_method_id'=> ['sometimes', 'nullable', 'exists:shipping_methods,id'],
            'subtotal'          => ['sometimes', 'integer', 'min:0'],
            'discount_amount'   => ['sometimes', 'integer', 'min:0'],
            'shipping_cost'     => ['sometimes', 'integer', 'min:0'],
            'total'             => ['sometimes', 'integer', 'min:0'],
            'payment_method'    => ['sometimes', 'string', 'max:50'],
            'payment_status'    => ['sometimes', 'string', 'max:50'],
            'status'            => ['sometimes', 'string', 'in:pending,processing,shipped,completed,canceled'],
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
