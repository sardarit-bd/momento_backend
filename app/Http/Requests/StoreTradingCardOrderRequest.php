<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTradingCardOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_slug' => ['required', 'string', 'exists:products,slug'],
            'package_slug' => [
                'required', 'string',
                Rule::exists('trading_card_packages', 'slug')->where('is_active', true),
            ],
            'template_id' => ['required', 'string', 'exists:templates,id'],
        ];
    }
}