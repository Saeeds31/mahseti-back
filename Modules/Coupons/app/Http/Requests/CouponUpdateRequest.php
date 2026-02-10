<?php

namespace Modules\Coupons\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'code'             => [
                'sometimes', 
                'string', 
                'max:50', 
                Rule::unique('coupons', 'code')->ignore($this->route('coupon'))
            ],
            'type'             => ['sometimes', 'in:percentage,fixed'],
            'value'            => ['sometimes', 'integer', 'min:0'],
            'max_discount'     => ['sometimes', 'integer', 'min:0'],
            'min_purchase'     => ['sometimes', 'integer', 'min:0'],
            'usage_limit'      => ['sometimes', 'integer', 'min:1'],
            'user_usage_limit' => ['sometimes', 'integer', 'min:1'],
            'start_date'       => ['sometimes', 'date'],
            'end_date'         => ['sometimes', 'date', 'after_or_equal:start_date'],
            'status'           => ['sometimes', 'boolean'],
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
