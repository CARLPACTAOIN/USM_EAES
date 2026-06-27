<?php

namespace App\Services;

use App\Models\User;
use App\Services\Contracts\AiServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaService implements AiServiceInterface
{
    protected string $apiUrl;
    protected ?string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiUrl = rtrim(config('services.ollama.api_url', 'https://ollama.com'), '/');
        $this->apiKey = config('services.ollama.api_key');
        $this->model  = config('services.ollama.model', 'gpt-oss:20b-cloud');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Public Interface
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Perform batch sentiment analysis on student comments.
     */
    public function analyzeSentiments(array $comments): array
    {
        if (empty($comments)) {
            return [];
        }

        if (empty($this->apiKey)) {
            Log::warning('Ollama API key is not configured. Skipping sentiment analysis.');
            return array_map(fn ($c) => ['id' => $c['id'], 'sentiment' => 'neutral', 'score' => 0.5], $comments);
        }

        $commentsJson = json_encode($comments);

        $prompt = "You are an expert student feedback sentiment classifier for the University of Southern Mindanao.\n"
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
            $responseBody = $this->callOllama($prompt);
            return $this->decodeSentimentResults($responseBody);
        } catch (\Exception $e) {
            Log::error('OllamaService::analyzeSentiments failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Parse a natural language query into structured filter parameters.
     */
    public function parseNaturalLanguageQuery(string $query, User $user): array
    {
        if (empty($this->apiKey)) {
            return [
                'error'   => 'Ollama API key is not configured.',
                'filters' => [],
            ];
        }

        $prompt = "You are an expert NLP assistant translating user queries into structured JSON parameters for query building on the database of USM Event Attendance & Evaluation System.\n"
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
            . "Return ONLY valid JSON — no markdown, no explanation, no code fences.\n\n"
            . "Natural Language Query:\n"
            . $query;

        try {
            $responseBody = $this->callOllama($prompt);
            $parsed = json_decode($responseBody, true);
        } catch (\Exception $e) {
            $httpStatus = (int) $e->getCode();
            Log::error('OllamaService::parseNaturalLanguageQuery failed: ' . $e->getMessage());

            if ($e->getMessage() === 'Ollama Cloud request timed out. Please try again.' || $httpStatus === 408) {
                return [
                    'error'   => 'Ollama Cloud request timed out. Please try again.',
                    'filters' => [],
                ];
            }

            if ($e->getMessage() === 'Ollama API key is not configured.') {
                return [
                    'error'   => 'Ollama API key is not configured.',
                    'filters' => [],
                ];
            }

            if ($httpStatus === 429) {
                return [
                    'error'   => 'The Ollama API rate limit was reached. Please wait a moment and try again.',
                    'filters' => [],
                ];
            }

            if ($httpStatus === 401 || $httpStatus === 403) {
                return [
                    'error'   => 'Ollama API authentication failed. Please check your OLLAMA_API_KEY in .env.',
                    'filters' => [],
                ];
            }

            // Surface the real error message
            $message = $e->getMessage();
            if (str_contains($message, '"message":')) {
                preg_match('/"message":\s*"([^"]+)"/', $message, $matches);
                $message = $matches[1] ?? $message;
            }

            return ['error' => 'Ollama API error: ' . $message, 'filters' => []];
        }

        if (!is_array($parsed)) {
            $parsed = ['target_table' => 'events', 'filters' => []];
        }

        return $parsed;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Call the Ollama cloud API (OpenAI-compatible /v1/chat/completions endpoint).
     */
    private function callOllama(string $prompt): string
    {
        if (empty($this->apiKey)) {
            throw new \Exception("Ollama API key is not configured.");
        }

        $endpoint = rtrim(config('services.ollama.api_url'), '/') . '/v1/chat/completions';
        $model = config('services.ollama.model');

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an AI assistant for an event attendance and evaluation system. Answer based only on the provided database context.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'stream' => false
        ];

        try {
            $response = Http::withToken($this->apiKey)
                ->connectTimeout(15)
                ->timeout(120)
                ->post($endpoint, $payload);

            $statusCode = $response->status();
            $responseBody = $response->body();

            // Log the final endpoint, selected model, status code, and response body, but never log the full API key.
            Log::info('Ollama API response details:', [
                'endpoint' => $endpoint,
                'model' => $model,
                'status_code' => $statusCode,
                'response_body' => $responseBody,
            ]);

            if ($response->failed()) {
                Log::error("Ollama API Error [{$statusCode}]: {$responseBody}", [
                    'endpoint' => $endpoint,
                    'model' => $model,
                ]);
                throw new \Exception("Ollama API Error [{$statusCode}]: {$responseBody}", $statusCode);
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Ollama API connection error/timeout: ' . $e->getMessage(), [
                'endpoint' => $endpoint,
                'model' => $model,
            ]);
            throw new \Exception("Ollama Cloud request timed out. Please try again.", 408);
        } catch (\Exception $e) {
            $isTimeout = str_contains($e->getMessage(), 'timed out') || 
                         str_contains($e->getMessage(), 'Connection timed out') ||
                         str_contains($e->getMessage(), 'cURL error 28');

            if ($isTimeout) {
                Log::error('Ollama API timeout detected: ' . $e->getMessage(), [
                    'endpoint' => $endpoint,
                    'model' => $model,
                ]);
                throw new \Exception("Ollama Cloud request timed out. Please try again.", 408);
            }

            if ($e->getCode() === 408) {
                throw $e;
            }

            Log::error('Ollama API unexpected error: ' . $e->getMessage(), [
                'endpoint' => $endpoint,
                'model' => $model,
            ]);
            throw $e;
        }

        $result = $response->json();

        // OpenAI-compatible response shape
        if (isset($result['choices'][0]['message']['content'])) {
            return $result['choices'][0]['message']['content'];
        }

        // Fallback: some Ollama endpoints return text directly
        if (isset($result['response'])) {
            return $result['response'];
        }

        throw new \Exception('Malformed Ollama API response: ' . json_encode($result));
    }

    /**
     * Decode and validate sentiment results from the model response.
     */
    private function decodeSentimentResults(string $responseBody): array
    {
        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
            throw new \UnexpectedValueException('Ollama sentiment response was not valid JSON.');
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
                'id'        => (string) $result['id'],
                'sentiment' => $sentiment,
                'score'     => max(0.0, min(1.0, $score)),
            ];
        }

        return $results;
    }
}
