<?php

namespace Modules\Sliders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SliderUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'       => ['sometimes', 'string', 'max:255'],
            'link'        => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'image'       => ['sometimes', 'file', 'max:255'],
            'type'        => ['sometimes', 'in:desktop,mobile'],
            'button_text' => ['sometimes', 'string', 'max:100'],
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
