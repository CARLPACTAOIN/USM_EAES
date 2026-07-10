<?php

namespace App\Support;

use Illuminate\Support\Str;

class YearLevels
{
    public const OPTIONS = [
        '1st_year' => '1st Year',
        '2nd_year' => '2nd Year',
        '3rd_year' => '3rd Year',
        '4th_year' => '4th Year',
        '5th_year' => '5th Year',
        'irregular' => 'Irregular',
    ];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::OPTIONS;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::OPTIONS);
    }

    public static function label(?string $value): string
    {
        if (!$value) {
            return 'N/A';
        }

        return self::OPTIONS[$value] ?? Str::of($value)->replace('_', ' ')->title()->toString();
    }
}
