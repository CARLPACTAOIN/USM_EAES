<?php

namespace App\Services;

use App\Services\Contracts\AiServiceInterface;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

class GeminiService implements AiServiceInterface
{
    protected $apiKey;
    protected $model;
    protected $fallbackModel;
    protected $timeout;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->model = config('services.gemini.model', 'gemini-1.5-pro');
        $this->fallbackModel = config('services.gemini.fallback_model', 'gemini-2.0-flash');
        $this->timeout = (int) config('services.gemini.request_timeout', 60);
    }

    /**
     * Send a request to the Google Gemini API.
     */
    protected function callGemini(string $prompt, string $modelName)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json'
            ]
        ];

        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

        if ($response->failed()) {
            $status = $response->status();
            $body   = $response->body();
            throw new \Exception("Gemini API Error [{$status}] ({$modelName}): {$body}", $status);
        }

        $result = $response->json();
        
        // Extract content from Gemini response
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return $result['candidates'][0]['content']['parts'][0]['text'];
        }

        throw new \Exception("Malformed Gemini Response: " . json_encode($result));
    }

    /**
     * Perform batch sentiment analysis on student comments.
     *
     * @param  array  $comments  An array of arrays, e.g. [['id' => 'uuid', 'comment' => '...']]
     * @return array
     */
    public function analyzeSentiments(array $comments): array
    {
        if (empty($comments)) {
            return [];
        }

        if (empty($this->apiKey)) {
            Log::warning('Gemini API key is not configured. Skipping sentiment analysis.');
            return array_map(function ($comment) {
                return [
                    'id' => $comment['id'],
                    'sentiment' => 'neutral',
                    'score' => 0.5
                ];
            }, $comments);
        }

        $commentsJson = json_encode($comments);

        $systemPrompt = "You are an expert student feedback sentiment classifier for the University of Southern Mindanao.\n"
            . "Analyze the following list of student comments. Determine if the sentiment is positive, neutral, or negative, and assign a confidence score (0.0 to 1.0).\n"
            . "Your response must be returned strictly in JSON format matching the schema requested.\n"
            . "Comments can be written in Tagalog, English, Taglish, or regional Mindanao dialects (e.g. Hiligaynon, Cebuano).\n\n"
            . "JSON Response Schema:\n"
            . "{\n"
            . "  \"results\": [\n"
            . "    {\n"
            . "      \"id\": <string>,\n"
            . "      \"sentiment\": \"positive\" | \"neutral\" | \"negative\",\n"
            . "      \"score\": <float>\n"
            . "    }\n"
            . "  ]\n"
            . "}\n\n"
            . "Input Data:\n"
            . $commentsJson;

        try {
            $responseBody = $this->callGemini($systemPrompt, $this->model);
            return $this->decodeSentimentResults($responseBody);
        } catch (\Exception $e) {
            Log::error("Primary model failed: " . $e->getMessage() . ". Falling back to 2.0 Flash.");
            try {
                $responseBody = $this->callGemini($systemPrompt, $this->fallbackModel);
                return $this->decodeSentimentResults($responseBody);
            } catch (\Exception $fallbackException) {
                Log::error("Fallback model failed: " . $fallbackException->getMessage());
                return [];
            }
        }
    }

    /**
     * Parse natural language query into structured SQL filter parameters.
     *
     * @param  string  $query
     * @param  \App\Models\User  $user
     * @return array
     */
    public function parseNaturalLanguageQuery(string $query, User $user): array
    {
        if (empty($this->apiKey)) {
            return [
                'error' => 'The Gemini API key is not configured. Please set GEMINI_API_KEY in your .env file.',
                'filters' => []
            ];
        }

        $systemPrompt = "You are an expert NLP assistant translating user queries into structured JSON parameters for query building on the database of USM Event Attendance & Evaluation System.\n"
            . "The system tracks events, event days, users (students), attendance records, and evaluations.\n\n"
            . "Do not include tenant or organization scope filters. The Laravel backend applies those security boundaries after parsing.\n\n"
            . "Convert the natural language query into filter parameters matching this JSON schema:\n"
            . "{\n"
            . "  \"target_table\": \"events\" | \"attendance_records\" | \"evaluations\",\n"
            . "  \"filters\": [\n"
            . "    {\n"
            . "      \"field\": \"<column_name>\",\n"
            . "      \"operator\": \"=\" | \"LIKE\" | \">\" | \"<\",\n"
            . "      \"value\": \"<value>\"\n"
            . "    }\n"
            . "  ]\n"
            . "}\n\n"
            . "Natural Language Query:\n"
            . $query;

        try {
            $responseBody = $this->callGemini($systemPrompt, $this->fallbackModel); // Use Flash for faster response on query parser
            $parsed = json_decode($responseBody, true);
        } catch (\Exception $e) {
            $httpStatus = (int) $e->getCode();
            Log::error('NLP Query Assistant parser failed: ' . $e->getMessage());

            if ($httpStatus === 429) {
                return [
                    'error' => 'The Gemini API free-tier quota is exhausted. '
                        . 'Please wait a minute and try again, or enable billing at https://ai.google.dev/pricing to increase your quota.',
                    'filters' => [],
                ];
            }

            // Surface the real API error message (strip the verbose JSON body if present)
            $message = $e->getMessage();
            if (str_contains($message, '"message":')) {
                preg_match('/"message":\s*"([^"]+)"/', $message, $matches);
                $message = $matches[1] ?? $message;
            }

            return ['error' => 'Gemini API error: ' . $message, 'filters' => []];
        }

        if (!is_array($parsed)) {
            $parsed = ['target_table' => 'events', 'filters' => []];
        }

        return $parsed;
    }

    private function decodeSentimentResults(string $responseBody): array
    {
        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
            throw new UnexpectedValueException('Gemini sentiment response was not valid JSON.');
        }

        $allowedSentiments = ['positive', 'neutral', 'negative'];
        $results = [];

        foreach ($decoded['results'] as $result) {
            if (!is_array($result) || !array_key_exists('id', $result)) {
                continue;
            }

            $sentiment = strtolower((string) ($result['sentiment'] ?? 'neutral'));
            if (!in_array($sentiment, $allowedSentiments, true)) {
                $sentiment = 'neutral';
            }

            $score = is_numeric($result['score'] ?? null) ? (float) $result['score'] : 0.5;

            $results[] = [
                'id' => (string) $result['id'],
                'sentiment' => $sentiment,
                'score' => max(0.0, min(1.0, $score)),
            ];
        }

        return $results;
    }
}
