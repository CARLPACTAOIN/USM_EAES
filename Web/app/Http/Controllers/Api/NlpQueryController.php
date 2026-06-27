<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Contracts\AiServiceInterface;
use App\Support\NlpQueryExecutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NlpQueryController extends Controller
{
    /**
     * Parse natural language query and retrieve records securely.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Services\Contracts\AiServiceInterface  $aiService
     * @return \Illuminate\Http\JsonResponse
     */
    public function query(Request $request, AiServiceInterface $aiService, NlpQueryExecutor $queryExecutor)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $queryText = $request->input('query');

        // Parse NL to structured query filters
        $parsedQuery = $aiService->parseNaturalLanguageQuery($queryText, $user);

        if (isset($parsedQuery['error'])) {
            return response()->json([
                'success' => false,
                'message' => 'NLP Query parsing failed: ' . $parsedQuery['error']
            ], 500);
        }

        try {
            $queryResult = $queryExecutor->execute($user, $parsedQuery);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'Query target table is unauthorized or invalid.'
            ], $exception->getStatusCode());
        }

        return response()->json([
            'success' => true,
            ...$queryResult,
        ]);
    }
}
