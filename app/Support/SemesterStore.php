<?php

namespace App\Support;

use App\Models\Semester;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class SemesterStore
{
    private const CACHE_KEY = 'semesters.all';

    private ?Collection $semesters = null;

    public function all(): Collection
    {
        if ($this->semesters === null) {
            $rows = Cache::rememberForever(
                self::CACHE_KEY,
                fn () => Semester::query()
                    ->orderByDesc('academic_year')
                    ->orderBy('term')
                    ->get()
                    ->toArray()
            );

            $this->semesters = Semester::hydrate($rows);
        }

        return $this->semesters;
    }

    public function current(): ?Semester
    {
        return $this->all()->firstWhere('is_current', true);
    }

    public function forget(): void
    {
        $this->semesters = null;
        Cache::forget(self::CACHE_KEY);
    }
}
