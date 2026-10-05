<?php

namespace Modules\Articles\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArticleUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'             => ['sometimes', 'string', 'max:255'],
            'slug'              => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('articles', 'slug')->ignore($this->route('article'))
            ],
            'image'             => ['sometimes', 'file', 'max:255'],
            'short_description' => ['sometimes', 'string'],
            'description'       => ['sometimes', 'string'],
            'meta_title'        => ['sometimes', 'string', 'max:255'],
            'meta_description'  => ['sometimes', 'string'],
            'read_time'         => ['sometimes', 'string', 'max:10'],
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
