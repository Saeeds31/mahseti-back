<?php

namespace Modules\ArticleCategories\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ArticleCategoryStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'slug'             => ['nullable', 'string', 'max:255', 'unique:article_categories,slug'],
            'parent_id'        => ['nullable', 'exists:article_categories,id'],
            'meta_title'       => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'description'      => ['nullable', 'string'],
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
