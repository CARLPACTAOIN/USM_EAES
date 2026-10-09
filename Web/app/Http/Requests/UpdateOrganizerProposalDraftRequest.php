<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizerProposalDraftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'proposal_category' => ['sometimes', 'nullable', 'in:program,project,activity'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'location_type' => ['sometimes', 'nullable', 'in:on-campus,off-campus,online'],
            'location_details' => ['sometimes', 'nullable', 'string', 'max:255'],
            'venue_id' => ['sometimes', 'nullable', 'uuid', 'exists:venues,id'],
            'venue_confirmation_office' => ['sometimes', 'nullable', 'string', 'max:255'],
            'venue_confirmation_date' => ['sometimes', 'nullable', 'date'],
            'venue_confirmation_reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'target_demographics' => ['sometimes', 'nullable', 'array'],
            'budget_allocations' => ['sometimes', 'nullable', 'array'],
            'resolution_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'event_days' => ['sometimes', 'nullable', 'array'],
            'evaluation_questions' => ['sometimes', 'nullable', 'array'],
            'canonical_packet' => ['sometimes', 'nullable', 'array'],
            'canonical_packet.activity_role' => ['prohibited'],
            'canonical_packet.proponent_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.proponent_position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.proponent_organization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.proponent_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'canonical_packet.proponent_contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'canonical_packet.rationale' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'canonical_packet.general_objective' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'canonical_packet.specific_objectives' => ['sometimes', 'nullable'],
            'canonical_packet.methodology' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'canonical_packet.major_activities' => ['sometimes', 'nullable'],
            'canonical_packet.expected_output' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'canonical_packet.beneficiaries' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
