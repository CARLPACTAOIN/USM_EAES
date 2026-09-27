<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Contracts\AiServiceInterface;
use App\Support\NlpQueryExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NlpQueryController extends Controller
{
    /**
     * Parse natural language query and retrieve records securely.
     *
     * @return JsonResponse
     */
    public function query(Request $request, AiServiceInterface $aiService, NlpQueryExecutor $queryExecutor)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:500',
            'school_year_id' => 'nullable|string',
            'school_year' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $queryText = $request->input('query');
        $selectedSchoolYear = $request->input('school_year_id') ?: $request->input('school_year');

        // Parse NL to structured query filters
        $parsedQuery = $aiService->parseNaturalLanguageQuery($queryText, $user);

        if (isset($parsedQuery['error'])) {
            return response()->json([
                'success' => false,
                'message' => 'NLP Query parsing failed: '.$parsedQuery['error'],
            ], 500);
        }

        try {
            $queryResult = $queryExecutor->execute($user, $parsedQuery, $selectedSchoolYear);
        } catch (HttpException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'Query target table is unauthorized or invalid.',
            ], $exception->getStatusCode());
        }

        return response()->json([
            'success' => true,
            ...$queryResult,
        ]);
    }
}
