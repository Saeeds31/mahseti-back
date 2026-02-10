<?php

namespace Modules\Comments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommentStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'content'          => ['required', 'string'],
            'user_id'          => ['required', 'exists:users,id'],
            'commentable_type' => ['required', 'string'], // می‌تونه کلاس مدل باشد
            'commentable_id'   => ['required', 'integer'], // id مدل مرتبط
            'parent_id'        => ['nullable', 'exists:comments,id'],
            'rating'           => ['nullable', 'integer', 'between:1,5'],
            'status'           => ['nullable', 'integer', 'in:0,1,2'],
            'ip'               => ['nullable', 'ip'],
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
