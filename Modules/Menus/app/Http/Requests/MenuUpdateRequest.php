<?php

namespace Modules\Menus\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MenuUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'     => ['sometimes', 'string', 'max:255'],
            'link'      => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'exists:menus,id'],
            'icon'      => ['sometimes', 'file', 'max:255'],
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
