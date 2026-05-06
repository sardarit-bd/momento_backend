<?php

namespace App\Http\Requests\TGC;

use Illuminate\Foundation\Http\FormRequest;

class CreateCardFromFaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'deck_id' => ['required', 'string'],
            'face_id' => ['required', 'string'],
            'has_proofed_face' => ['required', 'integer', 'in:0,1'],
            'has_proofed_back' => ['required', 'integer', 'in:0,1'],
        ];
    }
}
