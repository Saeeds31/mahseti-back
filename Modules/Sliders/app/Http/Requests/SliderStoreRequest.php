<?php

namespace Modules\Sliders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SliderStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'link'        => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image'       => ['nullable', 'file', 'max:255'], // می‌تونی بعدا image validation بذاری
            'type'        => ['nullable', 'in:desktop,mobile'],
            'button_text' => ['nullable', 'string', 'max:100'],
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
