<?php

namespace Modules\Specifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpecificationValueRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'specification_id' => 'required|exists:specifications,id',
            'value' => 'required|string|max:255',
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
