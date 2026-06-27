<?php

namespace App\Services\Contracts;

interface AiServiceInterface
{
    /**
     * Perform batch sentiment analysis on student comments.
     *
     * @param  array  $comments  [['id' => 'uuid', 'comment' => '...']]
     * @return array             [['id' => 'uuid', 'sentiment' => 'positive|neutral|negative', 'score' => float]]
     */
    public function analyzeSentiments(array $comments): array;

    /**
     * Parse a natural language query into structured filter parameters.
     *
     * @param  string  $query
     * @param  \App\Models\User  $user
     * @return array  ['target_table' => string, 'filters' => array] or ['error' => string, 'filters' => []]
     */
    public function parseNaturalLanguageQuery(string $query, \App\Models\User $user): array;
}
