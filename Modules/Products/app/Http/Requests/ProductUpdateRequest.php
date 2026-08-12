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
            'title'            => ['nullable', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'main_image'       => ['nullable', 'max:1024'],
            'meta_title'       => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            'status'           => ['nullable', 'in:draft,published,unpublished'],
            'sales_channel'           => ['nullable', 'in:online_only,in_store_only,both'],
            'discount_value'   => ['nullable', 'integer', 'min:0'],
            'discount_type'    => ['nullable', 'in:percent,fixed'],
            'barcode'          => ['nullable', 'string', 'max:100'],
            'sku'              => ['nullable', 'string', 'max:100'],
            'is_rechargeable'       => ['nullable', 'boolean'],

            'stock'            => ['nullable', 'integer', 'min:0'],
            'price'            => ['nullable', 'integer', 'min:0'],
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
