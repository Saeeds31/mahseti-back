<?php

namespace Modules\Menus\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MenuStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'     => ['required', 'string', 'max:255'],
            'link'      => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:menus,id'],
            'icon'      => ['nullable', 'file', 'max:255'],
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
