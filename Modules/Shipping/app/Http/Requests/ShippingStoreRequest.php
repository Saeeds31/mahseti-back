<?php

namespace Modules\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShippingStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'         => ['required', 'string', 'max:255'],
            'icon'         => ['nullable', 'file', 'max:1024'],
            'description'  => ['nullable', 'string'],
            'priority'  => ['nullable', 'integer'],
            'cost' => ['nullable', 'integer', 'min:0'],
            'status'       => ['nullable', 'boolean'],
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
