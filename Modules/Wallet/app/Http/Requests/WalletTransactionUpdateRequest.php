<?php

namespace Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WalletTransactionUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'wallet_id'   => ['sometimes', 'exists:wallets,id'],
            'type'        => ['sometimes', 'in:credit,debit'],
            'amount'      => ['sometimes', 'integer', 'min:0.01'],
            'description' => ['sometimes', 'string', 'max:255'],
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
