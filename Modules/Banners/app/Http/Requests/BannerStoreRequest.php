<?php

namespace Modules\Banners\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BannerStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title'         => ['nullable', 'string', 'max:255'],
            'image_desktop' => ['nullable', 'file', 'max:255'], // می‌تونی فایل image هم بذاری
            'image_mobile'  => ['nullable', 'file', 'max:255'],
            'link'          => ['nullable', 'string', 'max:255'],
            'position'      => ['nullable', 'string', 'max:50'],
            'status'        => ['nullable', 'boolean'],
            'ratio'        => ['nullable', 'integer'],
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
