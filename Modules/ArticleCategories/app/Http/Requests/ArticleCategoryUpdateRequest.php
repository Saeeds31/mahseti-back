<?php

namespace Modules\ArticleCategories\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArticleCategoryUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'            => ['sometimes', 'string', 'max:255'],
            'slug'             => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('article_categories', 'slug')->ignore($this->route('article_category'))
            ],
            'parent_id'        => ['sometimes', 'nullable', 'exists:article_categories,id'],
            'meta_title'       => ['sometimes', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'string'],
            'description'      => ['sometimes', 'string'],
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
