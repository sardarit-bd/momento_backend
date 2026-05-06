<?php

namespace App\Http\Requests\TGC;

use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route('cartId')) {
            $this->merge(['cart_id' => $this->route('cartId')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cart_id' => ['required', 'string'],
            'sku_id' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
