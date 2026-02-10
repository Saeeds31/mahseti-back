<?php

namespace Modules\Articles\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ArticleStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'             => ['required', 'string', 'max:255'],
            'slug'              => ['nullable', 'string', 'max:255', 'unique:articles,slug'],
            'image'             => ['nullable', 'file', 'max:255'], // می‌تونی بعداً rule فایل image بذاری
            'short_description' => ['nullable', 'string'],
            'description'       => ['nullable', 'string'],
            'meta_title'        => ['nullable', 'string', 'max:255'],
            'meta_description'  => ['nullable', 'string'],
            'read_time'         => ['nullable', 'string', 'max:250'], // دقیقه
            'category_ids' => ['required', 'array'],
            'category_ids.*' => ['exists:article_categories,id'],
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
