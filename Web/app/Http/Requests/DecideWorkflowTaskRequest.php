<?php

namespace App\Http\Requests;

use App\Models\ProposalWorkflowTask;
use App\Services\ProposalWorkflowTaskService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideWorkflowTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $this->user() !== null
            && $task instanceof ProposalWorkflowTask
            && app(ProposalWorkflowTaskService::class)->userCanViewTask($this->user(), $task);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                'string',
                Rule::in([
                    ProposalWorkflowTask::DECISION_APPROVED,
                    ProposalWorkflowTask::DECISION_REJECTED,
                    ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
                    ProposalWorkflowTask::DECISION_NOT_REQUIRED,
                ]),
            ],
            'remarks' => [
                'nullable',
                Rule::requiredIf(fn (): bool => in_array($this->input('decision'), [
                    ProposalWorkflowTask::DECISION_REJECTED,
                    ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
                ], true)),
                'string',
                'max:2000',
            ],
            'revision_target' => ['nullable', 'string', 'max:150'],
            'revision_target_type' => ['nullable', 'string', 'in:packet_section,attachment_requirement'],
            'revision_target_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('decision') === ProposalWorkflowTask::DECISION_REVISION_REQUESTED) {
                if (blank($this->input('revision_target_key')) && blank($this->input('revision_target'))) {
                    $validator->errors()->add('revision_target_key', 'Select the packet section or attachment requirement that needs revision.');
                    $validator->errors()->add('revision_target', 'Select the packet section or attachment requirement that needs revision.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'remarks.required' => 'Remarks are required for rejected and revision requested decisions.',
            'revision_target.required' => 'Select the packet section or attachment requirement that needs revision.',
        ];
    }
}
