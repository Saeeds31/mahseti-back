<?php

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettingStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'key'   => ['required', 'string', 'max:255', 'unique:settings,key'],
            'value' => ['nullable'],
            'type'  => ['nullable', 'in:string,number,boolean,json,text,file,image'],
            'group' => ['nullable', 'string', 'max:100'],
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
