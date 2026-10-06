<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Intestazione del messaggio, non solo un indirizzo: il valore
            // precompilato e' il nome del dipendente (SendMessageDraft::recipient).
            'recipient' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
