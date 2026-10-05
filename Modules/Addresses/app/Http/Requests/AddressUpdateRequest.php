<?php

namespace Modules\Addresses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddressUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'receiver_name' => ['sometimes', 'string', 'max:255'],
            'province_id'   => ['sometimes', 'exists:provinces,id'],
            'city_id'       => ['sometimes', 'exists:cities,id'],
            'postal_code'   => ['sometimes', 'string', 'max:20'],
            'address_line'  => ['sometimes', 'string'],
            'phone'         => ['sometimes', 'digits:11'], // فقط ۱۱ رقم
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
