<?php

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettingUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'key'   => ['sometimes', 'string', 'max:255', Rule::unique('settings', 'key')->ignore($this->route('setting'))],
            'value' => ['sometimes'],
            'type'  => ['sometimes', 'in:string,number,boolean,json,text,file,image'],
            'group' => ['sometimes', 'string', 'max:100'],
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
