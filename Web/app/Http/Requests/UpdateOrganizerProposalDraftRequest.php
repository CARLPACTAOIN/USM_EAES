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
            'activity_role' => ['prohibited'],

            'delegate_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'proponent_position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'proponent_contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'implementing_office' => ['sometimes', 'nullable', 'string', 'max:255'],
            'collaborating_office' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source_of_fund' => ['sometimes', 'nullable', 'string', 'max:255'],
            'budget_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'budget_breakdown' => ['sometimes', 'nullable', 'array'],
            'rationale' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'general_objective' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'specific_objectives' => ['sometimes', 'nullable'],
            'methodology' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'major_activities' => ['sometimes', 'nullable'],
            'program_activities' => ['sometimes', 'nullable', 'array'],
            'expected_output' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'beneficiaries' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'canonical_packet' => ['sometimes', 'nullable', 'array'],
            'canonical_packet.activity_role' => ['prohibited'],
            'canonical_packet.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.proposal_category' => ['sometimes', 'nullable', 'in:program,project,activity'],
            'canonical_packet.start_date' => ['sometimes', 'nullable', 'date'],
            'canonical_packet.end_date' => ['sometimes', 'nullable', 'date'],
            'canonical_packet.location_type' => ['sometimes', 'nullable', 'in:on-campus,off-campus,online'],
            'canonical_packet.venue_id' => ['sometimes', 'nullable', 'uuid', 'exists:venues,id'],
            'canonical_packet.location_details' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.venue_confirmation_office' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.venue_confirmation_date' => ['sometimes', 'nullable', 'date'],
            'canonical_packet.venue_confirmation_reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.proponent_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.proponent_position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.proponent_organization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.proponent_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'canonical_packet.proponent_contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'canonical_packet.implementing_office' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.collaborating_office' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.source_of_fund' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.budget_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'canonical_packet.budget_breakdown' => ['sometimes', 'nullable', 'array'],
            'canonical_packet.rationale' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'canonical_packet.general_objective' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'canonical_packet.specific_objectives' => ['sometimes', 'nullable'],
            'canonical_packet.methodology' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'canonical_packet.major_activities' => ['sometimes', 'nullable'],
            'canonical_packet.program_activities' => ['sometimes', 'nullable', 'array'],
            'canonical_packet.expected_output' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'canonical_packet.beneficiaries' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'canonical_packet.delegate_count' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'venue_confirmation_document' => ['sometimes', 'nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png'],
            'proposal_document' => ['sometimes', 'nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png'],
            'supporting_document' => ['sometimes', 'nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png'],
            'clear_venue_confirmation_document' => ['sometimes', 'boolean'],
            'clear_proposal_document' => ['sometimes', 'boolean'],
            'clear_supporting_document' => ['sometimes', 'boolean'],
        ];
    }
}
