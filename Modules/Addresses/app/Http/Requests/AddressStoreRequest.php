<?php

namespace Modules\Addresses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddressStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'user_id'       => ['required', 'exists:users,id'],
            'receiver_name' => ['required', 'string', 'max:255'],
            'province_id'   => ['required', 'exists:provinces,id'],
            'city_id'       => ['required', 'exists:cities,id'],
            'postal_code'   => ['required', 'string', 'max:20'],
            'address_line'  => ['required', 'string'],
            'phone'         =>['required', 'digits:11'],
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
