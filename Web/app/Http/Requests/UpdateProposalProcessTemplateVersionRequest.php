<?php

namespace App\Http\Requests;

use App\Models\ProposalProcessStep;
use App\Models\ProposalTemplateApplicabilityRule;
use App\Services\OrganizationManagementService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProposalProcessTemplateVersionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Super Admin (OSA)') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'template_name' => ['required', 'string', 'max:255'],
            'template_description' => ['nullable', 'string', 'max:2000'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*.step_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct:strict'],
            'steps.*.title' => ['required', 'string', 'max:255'],
            'steps.*.description' => ['nullable', 'string', 'max:1000'],
            'steps.*.step_type' => ['required', Rule::in($this->stepTypes())],
            'steps.*.phase' => ['nullable', 'string', 'max:80'],
            'steps.*.display_order' => ['required', 'integer', 'min:1', 'max:999', 'distinct:strict'],
            'steps.*.is_required' => ['nullable', 'boolean'],
            'steps.*.is_gate' => ['nullable', 'boolean'],
            'steps.*.assigned_role' => ['nullable', 'string', 'max:80'],
            'steps.*.assigned_scope' => ['nullable', 'string', 'max:80'],
            'steps.*.prerequisites' => ['nullable', 'array', 'max:10'],
            'steps.*.prerequisites.*' => ['string', 'max:80', 'distinct:strict'],
            'rules' => ['nullable', 'array', 'max:12'],
            'rules.*.priority' => ['required', 'integer', 'min:-1000', 'max:1000'],
            'rules.*.organizer_role' => ['nullable', Rule::in(['organizer', 'co_organizer', 'participant'])],
            'rules.*.organization_type' => ['nullable', Rule::in(OrganizationManagementService::ORGANIZATION_TYPES)],
            'rules.*.college_id' => ['nullable', 'uuid', 'exists:colleges,id'],
            'rules.*.event_domain' => ['nullable', Rule::in(['student_affairs', 'hr', 'academic_records', 'academic_unit'])],
            'rules.*.activity_category' => ['nullable', 'string', 'max:120'],
            'rules.*.location_type' => ['nullable', Rule::in(['on-campus', 'off-campus', 'online'])],
            'rules.*.is_default' => ['nullable', 'boolean'],
            'rules.*.status' => ['required', Rule::in([ProposalTemplateApplicabilityRule::STATUS_ACTIVE, ProposalTemplateApplicabilityRule::STATUS_INACTIVE])],
            'rules.*.effective_start' => ['nullable', 'date'],
            'rules.*.effective_end' => ['nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $stepKeys = collect($this->input('steps', []))
                    ->pluck('step_key')
                    ->filter()
                    ->map(fn (mixed $key): string => (string) $key)
                    ->values();
                $orderByKey = collect($this->input('steps', []))
                    ->filter(fn (array $step): bool => filled($step['step_key'] ?? null))
                    ->mapWithKeys(fn (array $step): array => [(string) $step['step_key'] => (int) ($step['display_order'] ?? 0)]);
                $dependencies = [];

                foreach ($this->input('steps', []) as $index => $step) {
                    $stepKey = (string) ($step['step_key'] ?? '');
                    $dependencies[$stepKey] = [];

                    foreach (($step['prerequisites'] ?? []) as $prerequisite) {
                        if ($prerequisite === $stepKey) {
                            $validator->errors()->add("steps.{$index}.prerequisites", 'A process step cannot depend on itself.');
                        }

                        if (! $stepKeys->contains($prerequisite)) {
                            $validator->errors()->add("steps.{$index}.prerequisites", 'Every prerequisite must reference another step key in this draft.');
                        }

                        if (($orderByKey[$prerequisite] ?? 0) >= ($orderByKey[$stepKey] ?? 0)) {
                            $validator->errors()->add("steps.{$index}.prerequisites", 'Prerequisites must be earlier ordered steps.');
                        }

                        $dependencies[$stepKey][] = (string) $prerequisite;
                    }
                }

                if ($this->hasDependencyCycle($dependencies)) {
                    $validator->errors()->add('steps', 'Step prerequisites must not contain cycles.');
                }

                foreach ($this->input('rules', []) as $index => $rule) {
                    if (filled($rule['effective_start'] ?? null) && filled($rule['effective_end'] ?? null)) {
                        if (strtotime((string) $rule['effective_end']) < strtotime((string) $rule['effective_start'])) {
                            $validator->errors()->add("rules.{$index}.effective_end", 'The effective end must be on or after the effective start.');
                        }
                    }
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $steps = collect($this->input('steps', []))
            ->filter(fn (mixed $step): bool => is_array($step))
            ->map(function (array $step): array {
                $step['step_key'] = str($step['step_key'] ?? '')->trim()->lower()->replace('-', '_')->toString();
                $step['prerequisites'] = collect($step['prerequisites'] ?? [])
                    ->filter(fn (mixed $value): bool => filled($value))
                    ->map(fn (mixed $value): string => str((string) $value)->trim()->lower()->replace('-', '_')->toString())
                    ->values()
                    ->all();

                return $step;
            })
            ->filter(function (array $step): bool {
                return filled($step['step_key'] ?? null)
                    || filled($step['title'] ?? null)
                    || filled($step['description'] ?? null)
                    || collect($step['prerequisites'] ?? [])->isNotEmpty();
            })
            ->values()
            ->all();

        $rules = collect($this->input('rules', []))
            ->filter(fn (mixed $rule): bool => is_array($rule))
            ->filter(function (array $rule): bool {
                $meaningfulKeys = [
                    'organizer_role',
                    'organization_type',
                    'college_id',
                    'event_domain',
                    'activity_category',
                    'location_type',
                    'effective_start',
                    'effective_end',
                ];

                $hasTargeting = collect($meaningfulKeys)
                    ->contains(fn (string $key): bool => filled($rule[$key] ?? null));

                return $hasTargeting
                    || (bool) ($rule['is_default'] ?? false)
                    || (int) ($rule['priority'] ?? 0) !== 0
                    || ($rule['status'] ?? ProposalTemplateApplicabilityRule::STATUS_ACTIVE) !== ProposalTemplateApplicabilityRule::STATUS_ACTIVE;
            })
            ->values()
            ->all();

        $this->merge([
            'template_name' => trim((string) $this->input('template_name')),
            'template_description' => $this->blankToNull($this->input('template_description')),
            'summary' => $this->blankToNull($this->input('summary')),
            'steps' => $steps,
            'rules' => $rules,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function stepTypes(): array
    {
        return [
            ProposalProcessStep::TYPE_ENDORSEMENT,
            ProposalProcessStep::TYPE_APPROVAL,
            ProposalProcessStep::TYPE_CLEARANCE,
            ProposalProcessStep::TYPE_INTAKE,
            ProposalProcessStep::TYPE_REVIEW,
        ];
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, array<int, string>>  $dependencies
     */
    private function hasDependencyCycle(array $dependencies): bool
    {
        $visiting = [];
        $visited = [];

        $visit = function (string $key) use (&$visit, &$dependencies, &$visiting, &$visited): bool {
            if (($visiting[$key] ?? false) === true) {
                return true;
            }

            if (($visited[$key] ?? false) === true) {
                return false;
            }

            $visiting[$key] = true;

            foreach ($dependencies[$key] ?? [] as $dependency) {
                if ($visit($dependency)) {
                    return true;
                }
            }

            $visiting[$key] = false;
            $visited[$key] = true;

            return false;
        };

        foreach (array_keys($dependencies) as $key) {
            if ($visit($key)) {
                return true;
            }
        }

        return false;
    }
}
