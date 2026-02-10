<?php

namespace Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WalletTransactionStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'wallet_id'   => ['required', 'exists:wallets,id'],
            'type'        => ['required', 'in:credit,debit'],
            'amount'      => ['required', 'integer', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
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
