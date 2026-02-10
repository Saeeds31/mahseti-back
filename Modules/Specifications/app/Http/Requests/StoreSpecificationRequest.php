<?php

namespace Modules\Specifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpecificationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255|unique:specifications,title',
        ];
    }
    public function authorize(): bool
    {
        return true;
    }
}
