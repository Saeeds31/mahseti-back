<?php

namespace Modules\Categories\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'            => ['sometimes', 'string', 'max:255'],
            'main_image'       => ['sometimes', 'file', 'max:255'],
            'icon'             => ['sometimes', 'file', 'max:255'],
            'slug'             => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('categories', 'slug')->ignore($this->route('category'))
            ],
            'meta_title'       => ['sometimes', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'string', 'max:255'],
            'description'      => ['sometimes', 'string'],
            'parent_id'        => ['sometimes', 'nullable', 'exists:categories,id'],

            'show_in_home'       => ['nullable', 'boolean'],
            'show_products_in_home'       => ['nullable', 'boolean'],
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
