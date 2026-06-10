<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:4000'],
            'sources'   => ['sometimes', 'array'],
            'sources.*' => ['string', 'in:court,matsne,echr,eu,german,const_court'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Message is required.',
            'message.min'      => 'Message must be at least 2 characters.',
            'message.max'      => 'Message must not exceed 4000 characters.',
        ];
    }
}
