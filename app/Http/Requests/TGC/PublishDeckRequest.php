<?php

namespace App\Http\Requests\TGC;

use Illuminate\Foundation\Http\FormRequest;

class PublishDeckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username'  => ['required', 'string', 'max:100'],
            'cards'     => ['required', 'array', 'min:1', 'max:5'],
            'cards.*'   => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'box'       => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],

            // Shipping
            'name'         => ['required', 'string', 'max:255'],
            'address1'     => ['required', 'string', 'max:500'],
            'address2'     => ['nullable', 'string', 'max:500'],
            'city'         => ['required', 'string', 'max:100'],
            'state'        => ['required', 'string', 'max:100'],
            'country'      => ['required', 'string', 'max:100'],
            'postal_code'  => ['required', 'string', 'max:20'],
            'phone_number' => ['required', 'string', 'max:50'],
            'company'      => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'cards.min'        => 'At least 1 custom card is required.',
            'cards.max'        => 'Maximum 5 custom cards allowed.',
            'box.required'     => 'A tuckbox image is required.',
            'address1.required' => 'Street address is required.',
            'state.required'   => 'State is required.',
            'country.required' => 'Country is required.',
        ];
    }
}