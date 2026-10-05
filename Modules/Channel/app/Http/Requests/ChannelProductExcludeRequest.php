<?php

namespace Modules\Channel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChannelProductExcludeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_excluded' => 'required|boolean',
        ];
    }
}