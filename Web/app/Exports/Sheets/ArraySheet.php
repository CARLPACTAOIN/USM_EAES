<?php

namespace App\Exports\Sheets;

use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ArraySheet implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    /**
     * @param list<list<string>> $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $rows
    ) {
    }

    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return Str::limit(preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $this->title) ?: 'Sheet', 31, '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->getStyle('1:1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFE6F4EA');

        return [];
    }
}
