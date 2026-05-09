<?php

namespace App\Http\Requests\TGC;

use Illuminate\Foundation\Http\FormRequest;

class CreateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:255'],
            'company'      => ['nullable', 'string', 'max:255'],
            'address1'     => ['required', 'string', 'max:255'],
            'address2'     => ['nullable', 'string', 'max:255'],
            'city'         => ['required', 'string', 'max:255'],
            'state'        => ['required', 'string', 'max:255'],
            'postal_code'  => ['required', 'string', 'max:20'],
            'country'      => ['required', 'string', 'size:2'],
            'phone_number' => ['required', 'string', 'max:20'],
        ];
    }
}