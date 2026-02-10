<?php

namespace Modules\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShippingUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name'         => ['sometimes', 'string', 'max:255'],
            'description'  => ['sometimes', 'string'],
            'default_cost' => ['sometimes', 'integer', 'min:0'],
            'status'       => ['sometimes', 'boolean'],
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
