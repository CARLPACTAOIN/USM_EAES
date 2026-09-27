<?php

namespace App\Support;

use App\Models\EvaluationFormVersion;
use App\Models\Event;
use App\Models\User;

class EventEvaluationForm
{
    public const DEFAULT_TITLE = 'Event Evaluation';

    /**
     * @return list<array{id: string, label: string, description: string, required: bool, scale_min: int, scale_max: int, scale_labels: array<string, string>}>
     */
    public static function questionsForEvent(Event $event): array
    {
        $event->loadMissing('evaluationFormVersion');
        $questions = $event->evaluationFormVersion?->questions;

        if (is_array($questions)) {
            $normalized = EvaluationQuestions::normalize($questions);

            if ($normalized !== []) {
                return $normalized;
            }
        }

        return EvaluationQuestions::defaultSchema();
    }

    /**
     * @return array<string, array{label: string, description: string, required: bool, scale_min: int, scale_max: int, scale_labels: array<string, string>}>
     */
    public static function questionMapForEvent(Event $event): array
    {
        return EvaluationQuestions::toQuestionMap(self::questionsForEvent($event));
    }

    public static function hasLockedForm(Event $event): bool
    {
        $event->loadMissing('evaluationFormVersion');
        $form = $event->evaluationFormVersion;

        return $form instanceof EvaluationFormVersion
            && $form->locked_at !== null
            && EvaluationQuestions::normalize($form->questions ?? []) !== [];
    }

    /**
     * @param  array<int|string, mixed>|null  $questions
     */
    public static function createForEvent(
        Event $event,
        ?User $creator,
        ?array $questions,
        ?string $title = null
    ): EvaluationFormVersion {
        $normalized = EvaluationQuestions::normalize($questions ?? []);

        if ($normalized === []) {
            $normalized = EvaluationQuestions::defaultSchema();
        }

        $form = EvaluationFormVersion::create([
            'event_id' => $event->id,
            'title' => $title ?: self::DEFAULT_TITLE,
            'questions' => $normalized,
            'created_by' => $creator?->id,
            'locked_at' => now(),
        ]);

        $event->forceFill(['evaluation_form_version_id' => $form->id])->save();
        $event->setRelation('evaluationFormVersion', $form);

        return $form;
    }
}
