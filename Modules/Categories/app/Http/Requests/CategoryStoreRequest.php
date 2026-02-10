<?php

namespace Modules\Categories\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategoryStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'main_image'       => ['nullable', 'file', 'max:255'], // می‌تونی بعدا image validation بذاری
            'icon'             => ['nullable', 'file', 'max:255'],
            'slug'             => ['nullable', 'string', 'max:255', 'unique:categories,slug'],
            'meta_title'       => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'parent_id'        => ['nullable', 'exists:categories,id'],
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
