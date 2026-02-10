<?php

namespace Modules\Attributes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttributeValueRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'attribute_id' => ['sometimes', 'exists:attributes,id'],
            'value'        => ['sometimes', 'string', 'max:255'],
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
