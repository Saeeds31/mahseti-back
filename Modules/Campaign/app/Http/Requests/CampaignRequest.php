<?php

namespace Modules\Campaign\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => 'required|string|max:255',
            'starts_at' => 'required|date',
            'ends_at'   => 'required|date|after:starts_at',
            'is_active' => 'boolean',

            'tiers'                    => 'required|array|min:1',
            'tiers.*.min_amount'       => 'required|integer|min:0',
            'tiers.*.max_amount'       => 'nullable|integer|min:1',
            'tiers.*.free_shipping'    => 'boolean',
            'tiers.*.discount_percent' => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            if ($v->errors()->isNotEmpty()) return;

            $tiers = collect($this->input('tiers'))->sortBy('min_amount')->values();
            $last  = $tiers->count() - 1;

            if ((int) $tiers[0]['min_amount'] !== 0) {
                $v->errors()->add('tiers', 'اولین پله باید از صفر شروع شود');
            }

            foreach ($tiers as $i => $t) {
                $max = $t['max_amount'] ?? null;

                if ($i === $last) {
                    if ($max !== null) $v->errors()->add('tiers', 'پله آخر باید بدون سقف باشد');
                    continue;
                }
                if ($max === null || (int) $max <= (int) $t['min_amount']) {
                    $v->errors()->add('tiers', 'سقف هر پله باید از شروع آن بیشتر باشد');
                } elseif ((int) $max !== (int) $tiers[$i + 1]['min_amount']) {
                    $v->errors()->add('tiers', 'بازه‌ها باید پیوسته باشند (سقف هر پله = شروع پله بعد)');
                }
            }
        });
    }
}
