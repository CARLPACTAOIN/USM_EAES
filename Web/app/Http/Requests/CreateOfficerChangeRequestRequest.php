<?php

namespace App\Http\Requests;

use App\Models\OfficerChangeRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOfficerChangeRequestRequest extends FormRequest
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
            'old_officer_id' => ['required', 'uuid', 'exists:organization_officers,id'],
            'request_type' => [
                'required',
                'string',
                Rule::in([
                    OfficerChangeRequest::TYPE_RESIGNATION,
                    OfficerChangeRequest::TYPE_REPLACEMENT,
                    OfficerChangeRequest::TYPE_REVOCATION,
                    OfficerChangeRequest::TYPE_CORRECTION,
                ]),
            ],
            'new_user_id' => [
                'nullable',
                'uuid',
                'exists:users,id',
                'required_if:request_type,'.OfficerChangeRequest::TYPE_REPLACEMENT,
            ],
            'position_title' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:3', 'max:3000'],
            'evidence_file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ];
    }
}
