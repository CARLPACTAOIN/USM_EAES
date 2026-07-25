<?php

namespace App\Support;

use Illuminate\Support\Str;

class EvaluationQuestions
{
    private const SCALE_LABELS = [
        '1' => 'Poor',
        '2' => 'Fair',
        '3' => 'Satisfactory',
        '4' => 'Very good',
        '5' => 'Excellent',
    ];

    /**
     * V1 evaluation categories used by the current backend coverage.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function all(): array
    {
        return [
            'attainment_of_objectives' => [
                'label' => 'Attainment of objectives',
                'description' => 'Were the stated objectives of the activity met?',
            ],
            'speaker_mastery' => [
                'label' => 'Speaker or facilitator mastery',
                'description' => 'How well did the speaker or facilitator deliver the content?',
            ],
            'venue_comfort' => [
                'label' => 'Venue and comfort',
                'description' => 'Was the venue appropriate, comfortable, and well prepared?',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return list<array{id: string, label: string, description: string, required: bool, scale_min: int, scale_max: int, scale_labels: array<string, string>}>
     */
    public static function defaultSchema(): array
    {
        $questions = [];

        foreach (self::all() as $key => $question) {
            $questions[] = self::schemaItem($key, $question['label'], $question['description']);
        }

        return $questions;
    }

    /**
     * @param array<int|string, mixed> $questions
     * @return list<array{id: string, label: string, description: string, required: bool, scale_min: int, scale_max: int, scale_labels: array<string, string>}>
     */
    public static function normalize(array $questions): array
    {
        $normalized = [];
        $seen = [];

        foreach (array_values($questions) as $index => $question) {
            if (!is_array($question)) {
                continue;
            }

            $label = trim((string) ($question['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $description = trim((string) ($question['description'] ?? ''));
            $baseId = self::normalizeId((string) ($question['id'] ?? ''), $label, $index);
            $id = $baseId;
            $counter = 2;

            while (isset($seen[$id])) {
                $id = $baseId . '_' . $counter;
                $counter++;
            }

            $seen[$id] = true;
            $normalized[] = self::schemaItem(
                $id,
                $label,
                $description,
                filter_var($question['required'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
            );
        }

        return $normalized;
    }

    /**
     * @param list<array{id: string, label: string, description: string, required: bool, scale_min: int, scale_max: int, scale_labels: array<string, string>}> $questions
     * @return array<string, array{label: string, description: string, required: bool, scale_min: int, scale_max: int, scale_labels: array<string, string>}>
     */
    public static function toQuestionMap(array $questions): array
    {
        $map = [];

        foreach ($questions as $question) {
            $map[$question['id']] = [
                'label' => $question['label'],
                'description' => $question['description'],
                'required' => $question['required'],
                'scale_min' => $question['scale_min'],
                'scale_max' => $question['scale_max'],
                'scale_labels' => $question['scale_labels'],
            ];
        }

        return $map;
    }

    /**
     * @return array{id: string, label: string, description: string, required: bool, scale_min: int, scale_max: int, scale_labels: array<string, string>}
     */
    private static function schemaItem(string $id, string $label, string $description, bool $required = true): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'description' => $description,
            'required' => $required,
            'scale_min' => 1,
            'scale_max' => 5,
            'scale_labels' => self::SCALE_LABELS,
        ];
    }

    private static function normalizeId(string $id, string $label, int $index): string
    {
        $candidate = trim($id) !== '' ? $id : $label;
        $candidate = Str::of($candidate)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        return $candidate !== '' ? $candidate : 'question_' . ($index + 1);
    }
}
