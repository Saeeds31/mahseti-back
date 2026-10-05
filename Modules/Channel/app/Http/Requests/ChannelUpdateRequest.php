<?php

namespace Modules\Channel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChannelUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware ادمین چک می‌کنه
    }

    public function rules(): array
    {
        return [
            'commission_percent' => 'sometimes|numeric|min:0|max:100',
            'credentials'        => 'sometimes|array',
            'settings'           => 'sometimes|array',
        ];
    }
}