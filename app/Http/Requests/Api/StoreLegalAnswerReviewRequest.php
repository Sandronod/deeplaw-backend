<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLegalAnswerReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reviewer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'overall_score' => ['required', 'integer', 'between:1,10'],
            'legal_accuracy_score' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'norm_coverage_score' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'case_law_score' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'source_routing_score' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'clarity_score' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'verdict' => [
                'required',
                'string',
                Rule::in(['correct', 'mostly_correct', 'partially_correct', 'incorrect', 'unsafe']),
            ],
            'correct_norms' => ['sometimes', 'array'],
            'incorrect_norms' => ['sometimes', 'array'],
            'missing_norms' => ['sometimes', 'array'],
            'correct_cases' => ['sometimes', 'array'],
            'irrelevant_cases' => ['sometimes', 'array'],
            'missing_cases' => ['sometimes', 'array'],
            'source_checks' => ['sometimes', 'array'],
            'improvement_actions' => ['sometimes', 'array'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:8000'],
        ];
    }
}
