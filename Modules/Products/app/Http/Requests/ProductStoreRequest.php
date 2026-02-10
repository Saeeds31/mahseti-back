<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'main_image'       => ['nullable', 'file', 'max:1024'], // می‌تونی بعدا image validation بذاری
            'meta_title'       => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            'status'           => ['nullable', 'in:draft,published,unpublished'],
            'discount_value'   => ['nullable', 'integer', 'min:0'],
            'discount_type'    => ['nullable', 'in:percent,fixed'],
            'barcode'          => ['nullable', 'string', 'max:100'],
            'sku'              => ['nullable', 'string', 'max:100'],
            'stock'            => ['nullable', 'integer', 'min:0'],
            'price'            => ['required', 'integer', 'min:0'],
            'video'            => ['nullable', 'file', 'max:4096'],
            'categories' => ['required', 'array'],
            'categories.*' => ['exists:categories,id'],
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
