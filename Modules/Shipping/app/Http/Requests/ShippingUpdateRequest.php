<?php

namespace Modules\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShippingUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules()
    {
        return [
            'title'         => ['sometimes', 'string', 'max:255'],
            'icon'         => ['sometimes', 'file', 'max:1024'],
            'description'  => ['sometimes', 'string'],
            'priority'  => ['sometimes', 'integer'],
            'cost' => ['sometimes', 'integer', 'min:0'],
            'status'       => ['sometimes', 'boolean'],
            'conditions' => 'nullable|array|min:1',
            'conditions.*.condition' => 'nullable|string',
            'conditions.*.type'  => 'nullable|string',
            'conditions.*.value' => 'nullable|integer|min:0',
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
