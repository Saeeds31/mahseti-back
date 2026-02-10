<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'            => ['sometimes', 'string', 'max:255'],
            'description'      => ['sometimes', 'string'],
            'main_image'       => ['sometimes', 'file', 'max:1024'],
            'meta_title'       => ['sometimes', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'string', 'max:255'],
            'status'           => ['sometimes', 'in:draft,published,unpublished'],
            'discount_value'   => ['sometimes', 'integer', 'min:0'],
            'discount_type'    => ['sometimes', 'in:percent,fixed'],
            'barcode'          => ['sometimes', 'string', 'max:100'],
            'sku'              => ['sometimes', 'string', 'max:100'],
            'stock'            => ['sometimes', 'integer', 'min:0'],
            'price'            => ['sometimes', 'integer', 'min:0'],
            'video'            => ['sometimes', 'file', 'max:4096'],
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
