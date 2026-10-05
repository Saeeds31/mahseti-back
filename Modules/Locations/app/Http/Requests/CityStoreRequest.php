<?php

namespace Modules\Locations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CityStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'province_id' => ['required', 'exists:provinces,id'],
            'name'        => ['required', 'string', 'max:255'],
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
