<?php

namespace App\Http\Requests\TGC;

use Illuminate\Foundation\Http\FormRequest;

class CreateDeckRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route('gameId') && ! $this->filled('game_id')) {
            $this->merge(['game_id' => $this->route('gameId')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'game_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'identity' => ['nullable', 'string', 'max:255'],
            'has_proofed_back' => ['nullable', 'integer', 'in:0,1'],
        ];
    }
}
