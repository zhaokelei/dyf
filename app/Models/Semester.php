<?php

namespace App\Models;

use App\Support\SemesterStore;
use Illuminate\Database\Eloquent\Model;

class Semester extends Model
{
    protected $fillable = [
        'academic_year',
        'term',
        'name',
        'start_date',
        'end_date',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public static function current(): ?self
    {
        return app(SemesterStore::class)->current();
    }

    public static function forAcademicYear(string $academicYear)
    {
        return static::where('academic_year', $academicYear)->orderBy('term');
    }

    public static function academicYears(): array
    {
        return static::query()
            ->select('academic_year')
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year')
            ->all();
    }

    public function termLabel(): string
    {
        return $this->term === 1 ? '第一学期' : '第二学期';
    }

    public function shortLabel(): string
    {
        return "{$this->academic_year} 学年{$this->termLabel()}";
    }
}
