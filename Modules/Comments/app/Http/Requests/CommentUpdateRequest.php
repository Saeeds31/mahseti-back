<?php

namespace Modules\Comments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommentUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'content'          => ['sometimes', 'string'],
            'user_id'          => ['sometimes', 'exists:users,id'],
            'commentable_type' => ['sometimes', 'string'],
            'commentable_id'   => ['sometimes', 'integer'],
            'parent_id'        => ['sometimes', 'nullable', 'exists:comments,id'],
            'rating'           => ['sometimes', 'integer', 'between:1,5'],
            'status'           => ['sometimes', 'integer', 'in:0,1,2'],
            'ip'               => ['sometimes', 'ip'],
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
