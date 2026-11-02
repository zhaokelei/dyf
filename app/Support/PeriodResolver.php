<?php

namespace App\Support;

use App\Models\Semester;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class PeriodResolver
{
    /**
     * Resolve "semester:ID" or "year:YYYY-YYYY" into a normalized period.
     */
    public static function resolve(Request $request): array
    {
        $value = (string) $request->input('period', '');

        if (preg_match('/^semester:(\d+)$/', $value, $matches)) {
            $semester = Semester::find((int) $matches[1]);

            if ($semester) {
                return self::forSemester($semester, $value);
            }
        }

        if (preg_match('/^year:(\d{4}-\d{4})$/', $value, $matches)) {
            $semesters = Semester::forAcademicYear($matches[1])->get();

            if ($semesters->isNotEmpty()) {
                return [
                    'value' => $value,
                    'scope' => 'year',
                    'label' => "{$matches[1]} 学年（汇总）",
                    'academic_year' => $matches[1],
                    'term' => null,
                    'semester_ids' => $semesters->pluck('id')->all(),
                    'semester' => null,
                ];
            }
        }

        $semester = Semester::current() ?? Semester::orderByDesc('id')->first();

        if (! $semester) {
            return [
                'value' => '',
                'scope' => 'semester',
                'label' => '未设置学期',
                'academic_year' => null,
                'term' => null,
                'semester_ids' => [],
                'semester' => null,
            ];
        }

        return self::forSemester($semester, "semester:{$semester->id}");
    }

    /**
     * Resolve ranking window: all / week / month / custom.
     */
    public static function ranking(Request $request): array
    {
        $mode = (string) $request->input('rank_period', 'all');
        $mode = in_array($mode, ['all', 'week', 'month', 'custom'], true) ? $mode : 'all';
        $now = CarbonImmutable::now();
        $start = null;
        $end = null;
        $startDate = null;
        $endDate = null;

        if ($mode === 'week') {
            $start = $now->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
            $end = $now->endOfWeek(CarbonImmutable::SUNDAY)->endOfDay();
        } elseif ($mode === 'month') {
            $start = $now->startOfMonth()->startOfDay();
            $end = $now->endOfMonth()->endOfDay();
        } elseif ($mode === 'custom') {
            $startDate = $request->input('start_date') ?: null;
            $endDate = $request->input('end_date') ?: null;

            if ($startDate) {
                $start = CarbonImmutable::parse($startDate)->startOfDay();
            }

            if ($endDate) {
                $end = CarbonImmutable::parse($endDate)->endOfDay();
            }

            if ($start && $end && $end->lt($start)) {
                [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
                [$startDate, $endDate] = [$endDate, $startDate];
            }
        }

        return [
            'rank_period' => $mode,
            'start' => $start,
            'end' => $end,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'label' => match ($mode) {
                'week' => '本周排行',
                'month' => '本月排行',
                'custom' => '自定义日期排行',
                default => '总排行',
            },
        ];
    }

    private static function forSemester(Semester $semester, string $value): array
    {
        return [
            'value' => $value,
            'scope' => 'semester',
            'label' => $semester->name,
            'academic_year' => $semester->academic_year,
            'term' => $semester->term,
            'semester_ids' => [$semester->id],
            'semester' => [
                'id' => $semester->id,
                'name' => $semester->name,
                'is_current' => $semester->is_current,
            ],
        ];
    }
}
