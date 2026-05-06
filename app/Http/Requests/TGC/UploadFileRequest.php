<?php

namespace App\Http\Requests\TGC;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'folder_id' => ['required', 'string'],
            'file' => ['required', 'file', 'max:20480'],
            'has_proofed' => ['required', 'integer', 'in:0,1'],
        ];
    }

    /**
     * Always return JSON errors for this API endpoint.
     * Otherwise Laravel can redirect on validation failure when the client
     * doesn't set `Accept: application/json` (common with browser form posts).
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422),
        );
    }
}
