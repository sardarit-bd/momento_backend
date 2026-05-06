<?php

namespace App\Http\Requests\TGC;

use Illuminate\Foundation\Http\FormRequest;

class ProofCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'card' => ['required', 'array'],
            'card.back_id' => ['nullable', 'string'],
        ];
    }
}
