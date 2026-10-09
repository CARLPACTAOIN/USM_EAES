<?php

namespace App\Http\Requests;

class SubmitParticipantPacketRequest extends ParticipantPacketRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->commonRules(), [
            'delegate_count' => ['nullable', 'integer', 'min:1'],
            'ppa_softcopy' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);
    }
}
