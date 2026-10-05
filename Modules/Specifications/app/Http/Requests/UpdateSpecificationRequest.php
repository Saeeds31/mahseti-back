<?php

namespace Modules\Specifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpecificationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $id = $this->route('id'); // از روت آی‌دی می‌گیریم
        return [
            'title' => 'required|string|max:255|unique:specifications,title,' . $id,
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
