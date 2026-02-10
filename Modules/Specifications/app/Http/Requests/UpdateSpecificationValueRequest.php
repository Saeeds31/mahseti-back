<?php

namespace Modules\Specifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpecificationValueRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $id = $this->route('id');
        return [
            'specification_id' => 'required|exists:specifications,id',
            'value' => 'required|string|max:255|unique:specification_values,value,' . $id,
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
