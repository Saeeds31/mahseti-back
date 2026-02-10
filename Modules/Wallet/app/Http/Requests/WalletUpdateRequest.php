<?php

namespace Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WalletUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'exists:users,id'],
            'balance' => ['sometimes', 'integer'],
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
