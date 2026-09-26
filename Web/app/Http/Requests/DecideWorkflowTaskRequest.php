<?php

namespace App\Http\Requests;

use App\Models\ProposalWorkflowTask;
use App\Services\ProposalWorkflowTaskService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'decision' => ['required', 'string'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
