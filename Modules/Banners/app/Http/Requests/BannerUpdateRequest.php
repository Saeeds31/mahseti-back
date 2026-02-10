<?php

namespace Modules\Banners\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BannerUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'         => ['sometimes', 'string', 'max:255'],
            'image_desktop' => ['sometimes', 'file', 'max:255'],
            'image_mobile'  => ['sometimes', 'file', 'max:255'],
            'link'          => ['sometimes', 'string', 'max:255'],
            'position'      => ['sometimes', 'string', 'max:50'],
            'status'        => ['sometimes', 'boolean'],
            'ratio'        => ['sometimes', 'integer'],

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
