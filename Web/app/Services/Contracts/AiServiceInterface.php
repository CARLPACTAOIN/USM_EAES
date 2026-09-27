<?php

namespace App\Services\Contracts;

use App\Models\User;

interface AiServiceInterface
{
    /**
     * Perform batch sentiment analysis on student comments.
     *
     * @param  array  $comments  [['id' => 'uuid', 'comment' => '...']]
     * @return array [['id' => 'uuid', 'sentiment' => 'positive|neutral|negative', 'score' => float]]
     */
    public function analyzeSentiments(array $comments): array;

    /**
     * Parse a natural language query into structured filter parameters.
     *
     * @return array ['target_table' => string, 'filters' => array] or ['error' => string, 'filters' => []]
     */
    public function parseNaturalLanguageQuery(string $query, User $user): array;
}
