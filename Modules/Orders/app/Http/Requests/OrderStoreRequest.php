<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'user_id'           => ['required', 'exists:users,id'],
            'address_id'        => ['required', 'exists:addresses,id'],
            'shipping_method_id'=> ['nullable', 'exists:shipping_methods,id'],
            'subtotal'          => ['required', 'integer', 'min:0'],
            'discount_amount'   => ['nullable', 'integer', 'min:0'],
            'shipping_cost'     => ['nullable', 'integer', 'min:0'],
            'total'             => ['required', 'integer', 'min:0'],
            'payment_method'    => ['nullable', 'string', 'max:50'],
            'payment_status'    => ['nullable', 'string', 'max:50'],
            'status'            => ['nullable', 'string', 'in:pending,processing,shipped,completed,canceled'],
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
