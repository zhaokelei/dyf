<?php

namespace App\Support;

use Illuminate\Http\Request;

class PageSize
{
    public const OPTIONS = ['10', '20', '50', '100', 'all'];

    /**
     * @return array{per_page:string, limit:int}
     */
    public static function resolve(Request $request, int $default = 20): array
    {
        $raw = (string) $request->input('per_page', (string) $default);

        if (! in_array($raw, self::OPTIONS, true)) {
            $raw = (string) $default;
        }

        return [
            'per_page' => $raw,
            'limit' => $raw === 'all' ? 100000 : (int) $raw,
        ];
    }
}
