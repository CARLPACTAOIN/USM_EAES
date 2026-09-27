<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Services\Contracts\AiServiceInterface;
use App\Support\NlpQueryExecutor;
use Illuminate\Support\Collection;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiInsightsQuery extends Component
{
    private const ADMIN_ROLES = [
        'Super Admin (OSA)',
        'USG Admin',
        'LSG Admin',
        'Society Admin',
        'Academic Unit Admin',
        'Office Event Admin',
        'HR Admin',
    ];

    public string $query = '';

    public ?string $schoolYearId = null;

    public ?string $queryText = null;

    public ?array $queryResult = null;

    public ?string $aiError = null;

    public function mount(string $queryText = '', ?array $queryResult = null, ?string $aiError = null, ?string $schoolYearId = null): void
    {
        $this->query = $queryText;
        $this->schoolYearId = $schoolYearId;
        $this->queryText = $queryText !== '' ? $queryText : null;
        $this->queryResult = $queryResult;
        $this->aiError = $aiError;
    }

    public function runSuggestion(string $suggestion): void
    {
        $this->query = $suggestion;
        $this->runQuery();
    }

    public function runQuery(): void
    {
        $user = $this->authorizedUser();

        $this->validate([
            'query' => ['required', 'string', 'max:500'],
        ], [], [
            'query' => 'question',
        ]);

        $this->queryText = $this->query;
        $this->queryResult = null;
        $this->aiError = null;

        $parsedQuery = app(AiServiceInterface::class)->parseNaturalLanguageQuery($this->query, $user);

        if (isset($parsedQuery['error'])) {
            $this->aiError = 'NLP Query parsing failed: '.$parsedQuery['error'];

            return;
        }

        try {
            $queryResult = app(NlpQueryExecutor::class)->execute($user, $parsedQuery, $this->schoolYearId);
        } catch (HttpException $exception) {
            $this->aiError = $exception->getMessage() ?: 'Query target table is unauthorized or invalid.';

            return;
        }

        $queryResult['results'] = $this->normalizeRows($queryResult['results'] ?? collect());

        $this->queryResult = $queryResult;
    }

    public function render()
    {
        return view('livewire.dashboard.ai-insights-query');
    }

    private function authorizedUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->hasAnyRole(self::ADMIN_ROLES), 403);

        return $user;
    }

    private function normalizeRows(mixed $rows): array
    {
        if ($rows instanceof Collection) {
            return $rows->map(fn ($row): array => (array) $row)->all();
        }

        if (is_array($rows)) {
            return array_map(fn ($row): array => (array) $row, $rows);
        }

        return [];
    }
}
