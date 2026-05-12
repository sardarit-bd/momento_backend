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
            'username' => ['required', 'string', 'max:100'],
            'cards'    => ['required', 'array', 'min:1', 'max:5'],
            'cards.*'  => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'box'      => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'cards.min' => 'At least 1 custom card is required.',
            'cards.max' => 'Maximum 5 custom cards allowed.',
            'box.required' => 'A tuckbox image is required.',
        ];
    }
}