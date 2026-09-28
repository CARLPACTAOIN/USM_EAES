<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ParticipantPacketRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Common validation rules shared between draft and submission.
     *
     * @return array<string, array<int, string>>
     */
    protected function commonRules(): array
    {
        return [
            'proponent_name' => ['nullable', 'string', 'max:255'],
            'proponent_position' => ['nullable', 'string', 'max:255'],
            'proponent_organization' => ['nullable', 'string', 'max:255'],
            'proponent_email' => ['nullable', 'email', 'max:255'],
            'proponent_contact_number' => ['nullable', 'string', 'max:50'],
            'beneficiaries' => ['nullable', 'string', 'max:1000'],
            'source_of_fund' => ['nullable', 'string', 'max:255'],
            'budget_cost' => ['nullable', 'numeric', 'min:0'],
            'budget_breakdown' => ['nullable', 'array'],
            'rationale' => ['nullable', 'string', 'max:2000'],
            'supporting_document' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ];
    }
}
