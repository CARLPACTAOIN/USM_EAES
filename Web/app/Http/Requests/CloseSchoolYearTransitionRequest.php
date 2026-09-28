<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CloseSchoolYearTransitionRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function closeReason(): string
    {
        $reason = trim((string) ($this->validated('reason') ?? ''));

        return $reason !== '' ? $reason : 'Transition period closed.';
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $reason = trim((string) $this->input('reason'));

            $this->merge([
                'reason' => $reason !== '' ? $reason : null,
            ]);
        }
    }
}
