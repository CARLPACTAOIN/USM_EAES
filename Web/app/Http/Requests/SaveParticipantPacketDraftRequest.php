<?php

namespace App\Http\Requests;

class SaveParticipantPacketDraftRequest extends ParticipantPacketRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return array_merge($this->commonRules(), [
            'delegate_count' => ['nullable', 'integer', 'min:0'],
            'ppa_softcopy' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);
    }
}
