<?php

namespace Modules\Coupons\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CouponStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'code'             => ['required', 'string', 'max:50', 'unique:coupons,code'],
            'type'             => ['required', 'in:percentage,fixed'],
            'value'            => ['required', 'integer', 'min:0'],
            'max_discount'     => ['nullable', 'integer', 'min:0'],
            'min_purchase'     => ['nullable', 'integer', 'min:0'],
            'usage_limit'      => ['nullable', 'integer', 'min:1'],
            'user_usage_limit' => ['nullable', 'integer', 'min:1'],
            'start_date'       => ['nullable', 'date'],
            'end_date'         => ['nullable', 'date', 'after_or_equal:start_date'],
            'status'           => ['nullable', 'boolean'],
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
