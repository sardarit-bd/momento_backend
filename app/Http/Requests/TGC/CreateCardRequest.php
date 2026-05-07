<?php

namespace App\Http\Requests\TGC;

use Illuminate\Foundation\Http\FormRequest;

class CreateCardRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route('deckId')) {
            $this->merge(['deck_id' => $this->route('deckId')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'deck_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'face_image' => ['required', 'file', 'mimes:png,jpeg', 'max:20480'],
            'back_image' => ['nullable', 'file', 'mimes:png,jpeg', 'max:20480'],
            'face_file_id' => ['nullable', 'string'], 
            'back_file_id' => ['nullable', 'string'],
            'folder_id' => ['nullable', 'string'],
        ];
    }
}
