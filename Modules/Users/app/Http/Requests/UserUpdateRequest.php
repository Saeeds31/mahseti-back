<?php

namespace Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'full_name'     => ['sometimes', 'string', 'max:255'],
            'mobile'        => ['sometimes', 'string', 'size:11', Rule::unique('users','mobile')->ignore($this->route('user'))],
            'password'      => ['sometimes', 'string', 'min:6'],
            'national_code' => ['sometimes', 'string', 'size:10'],
            'birth_date'    => ['sometimes', 'date'],
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
