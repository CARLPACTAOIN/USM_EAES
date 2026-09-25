<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitOfficerRosterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'officers' => ['nullable', 'array'],
            'officers.*.user_id' => ['required', 'uuid', 'exists:users,id'],
            'officers.*.position_key' => ['required', 'string', 'max:50'],
            'officers.*.position_title' => ['nullable', 'string', 'max:100'],
            'attestation_text' => ['nullable', 'string', 'max:5000'],
            'evidence_file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ];
    }
}
