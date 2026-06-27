<?php

namespace App\Exports;

use App\Exports\Sheets\ArraySheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class EventEvaluationExport implements WithMultipleSheets
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    public function sheets(): array
    {
        return [
            new ArraySheet('Summary', $this->payload['summary_rows']),
            new ArraySheet('Evaluations', $this->payload['evaluation_rows']),
        ];
    }
}
