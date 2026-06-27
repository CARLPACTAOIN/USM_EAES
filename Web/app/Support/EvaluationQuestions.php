<?php

namespace App\Support;

class EvaluationQuestions
{
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
}
