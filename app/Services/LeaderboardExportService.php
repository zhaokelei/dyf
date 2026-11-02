<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Models\ScoreRecord;

class LeaderboardExportService
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function details(array $rows, array $filters): array
    {
        $userIds = collect($rows)->pluck('id')->all();

        if ($userIds === []) {
            return [];
        }

        $records = ScoreRecord::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')
            ->whereIn('semester_id', $filters['semester_ids'] ?: [-1])
            ->when($filters['start'] ?? null, fn ($q, $value) => $q->whereRaw('DATE(COALESCE(applied_on, created_at)) >= ?', [$value->toDateString()]))
            ->when($filters['end'] ?? null, fn ($q, $value) => $q->whereRaw('DATE(COALESCE(applied_on, created_at)) <= ?', [$value->toDateString()]))
            ->with([
                'category:id,name',
                'personalApplication:id,activity_name,applied_on,description',
                'groupApplication:id,title,created_at',
            ])
            ->orderByRaw('COALESCE(applied_on, DATE(created_at))')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $details = [];

        foreach ($rows as $row) {
            $items = $records->get($row['id']);

            if (! $items) {
                continue;
            }

            $mapped = $items->map(function (ScoreRecord $record) {
                if ($record->source_type === 'personal') {
                    $application = $record->personalApplication;

                    return [
                        'date' => $record->applied_on?->format('Y-m-d')
                            ?? $application?->applied_on?->format('Y-m-d')
                            ?? $record->created_at?->format('Y-m-d'),
                        'category' => $record->category?->name,
                        'title' => $application?->activity_name ?: ($record->description ?: '—'),
                        'score' => (float) $record->score,
                    ];
                }

                return [
                    'date' => $record->applied_on?->format('Y-m-d')
                        ?? ($record->source_type === 'group'
                            ? ($record->groupApplication?->created_at?->format('Y-m-d') ?? $record->created_at?->format('Y-m-d'))
                            : $record->created_at?->format('Y-m-d')),
                    'category' => $record->category?->name,
                    'title' => $record->description ?: '—',
                    'score' => (float) $record->score,
                ];
            })->values()->all();

            $details[] = [
                'name' => $row['name'],
                'student_id' => $row['student_id'],
                'class_name' => $row['class_name'],
                'items' => $mapped,
            ];
        }

        return $details;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function summaryClasses(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $classIds = collect($rows)->pluck('class_id')->filter()->unique()->values()->all();
        $classes = SchoolClass::query()
            ->with(['admins:id,name'])
            ->whereIn('id', $classIds)
            ->get()
            ->keyBy('id');

        $groups = [];

        foreach ($rows as $row) {
            $classId = $row['class_id'] ?? null;
            $key = $classId ? "id:{$classId}" : 'name:'.($row['college'] ?? '').'|'.($row['class_name'] ?? '');
            $schoolClass = $classId ? $classes->get($classId) : null;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'college' => $row['college'] ?: '未设置学院',
                    'class_name' => $row['class_name'] ?: '未分班',
                    'counselor' => $schoolClass
                        ? $schoolClass->admins->pluck('name')->implode('、')
                        : '',
                    'students' => [],
                ];
            }

            $groups[$key]['students'][] = [
                'name' => $row['name'],
                'score' => $row['total_score'],
            ];
        }

        $groups = array_values($groups);

        usort($groups, fn ($a, $b) => strcmp(
            $a['college'].'|'.$a['class_name'],
            $b['college'].'|'.$b['class_name']
        ));

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $period
     */
    public function summaryPeriodTitle(array $period): string
    {
        $termLabel = match ((int) ($period['term'] ?? 0)) {
            1 => '一',
            2 => '二',
            3 => '三',
            default => '',
        };

        if (! $period['academic_year']) {
            return (string) ($period['label'] ?? '');
        }

        return $period['scope'] === 'semester' && $termLabel !== ''
            ? "{$period['academic_year']} 学年 第（{$termLabel}）学期"
            : "{$period['academic_year']} 学年 汇总";
    }
}
