<?php

namespace Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'full_name'     => ['required', 'string', 'max:255'],
            'mobile'        => ['required', 'string', 'size:11', 'unique:users,mobile'],
            'password'      => ['required', 'string', 'min:6'],
            'national_code' => ['nullable', 'string', 'size:10'],
            'birth_date'    => ['nullable', 'date'],
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
